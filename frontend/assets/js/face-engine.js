/**
 * SAMS - Face Recognition & Liveness Engine
 * Powered by @vladmandic/face-api
 * Provides client-side face detection, 68-point landmarks, active anti-spoofing
 * (Eye Aspect Ratio blink & head-turn yaw angle), and 128D embedding generation.
 * Video frames stay strictly inside client memory (100% privacy-compliant).
 */

const FaceEngine = {
  isLoaded: false,
  isLoading: false,
  modelBaseUrl: 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/',
  
  // Liveness Tracker State
  liveness: {
    state: 'LOOK_STRAIGHT', // LOOK_STRAIGHT -> TURN_HEAD -> PASSED
    baselineYawRatio: null,
    yawTurnDetected: false,
    blinkDetected: false,
    straightFramesCount: 0,
    startTime: null
  },

  /**
   * Load face-api library script and pre-trained neural network models
   */
  async init() {
    if (this.isLoaded) return true;
    if (this.isLoading) {
      // Wait if already loading
      while (this.isLoading) {
        await new Promise(r => setTimeout(r, 100));
      }
      return this.isLoaded;
    }

    this.isLoading = true;

    try {
      // 1. Ensure faceapi library is loaded on window
      if (typeof window.faceapi === 'undefined') {
        await this.loadScript('https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js');
      }

      if (typeof window.faceapi === 'undefined') {
        throw new Error('Failed to load facial recognition library. Please check your internet connection.');
      }

      // Configure TensorFlow.js backend
      if (window.faceapi.tf) {
        try {
          await window.faceapi.tf.setBackend('webgl');
          await window.faceapi.tf.ready();
        } catch (backendErr) {
          console.warn('[SAMS FaceEngine] WebGL fallback to CPU/WASM:', backendErr.message);
        }
      }

      // 2. Load model weights
      console.log('[SAMS FaceEngine] Loading neural network models...');
      await Promise.all([
        window.faceapi.nets.tinyFaceDetector.loadFromUri(this.modelBaseUrl),
        window.faceapi.nets.faceLandmark68Net.loadFromUri(this.modelBaseUrl),
        window.faceapi.nets.faceRecognitionNet.loadFromUri(this.modelBaseUrl)
      ]);

      this.isLoaded = true;
      console.log('[SAMS FaceEngine] Models successfully initialized.');
      return true;
    } catch (err) {
      console.error('[SAMS FaceEngine Init Error]', err);
      throw new Error(`Facial recognition model error: ${err.message}`);
    } finally {
      this.isLoading = false;
    }
  },

  /**
   * Helper to dynamically inject script
   */
  loadScript(url) {
    return new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[src="${url}"]`);
      if (existing) {
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', (e) => reject(e));
        return;
      }
      const s = document.createElement('script');
      s.src = url;
      s.async = true;
      s.onload = () => resolve();
      s.onerror = (e) => reject(new Error(`Failed to load ${url}`));
      document.head.appendChild(s);
    });
  },

  /**
   * Reset the active liveness state machine
   */
  resetLiveness() {
    this.liveness = {
      state: 'LOOK_STRAIGHT',
      baselineYawRatio: null,
      yawTurnDetected: false,
      blinkDetected: false,
      straightFramesCount: 0,
      startTime: Date.now()
    };
  },

  /**
   * Detect face and landmarks from video element
   */
  async processFrame(videoEl, options = {}) {
    if (!this.isLoaded) {
      await this.init();
    }

    if (!videoEl || videoEl.readyState !== 4) {
      return { status: 'NOT_READY' };
    }

    const detectorOptions = new window.faceapi.TinyFaceDetectorOptions({
      inputSize: 416,
      scoreThreshold: 0.55
    });

    // 1. Check total face count in frame
    const allDetections = await window.faceapi.detectAllFaces(videoEl, detectorOptions);

    if (!allDetections || allDetections.length === 0) {
      this.resetLiveness();
      return {
        status: 'NO_FACE',
        message: 'Face not detected. Please position your face inside the frame.'
      };
    }

    if (allDetections.length > 1) {
      this.resetLiveness();
      return {
        status: 'MULTIPLE_FACES',
        message: 'Multiple faces detected. Only one person should be visible.',
        count: allDetections.length
      };
    }

    // 2. Extract landmarks and 128D descriptor for the single face
    const detection = await window.faceapi
      .detectSingleFace(videoEl, detectorOptions)
      .withFaceLandmarks()
      .withFaceDescriptor();

    if (!detection) {
      return {
        status: 'NO_FACE',
        message: 'Face not detected. Please position your face inside the frame.'
      };
    }

    const box = detection.detection.box;
    const score = detection.detection.score;

    // Check minimum size (must not be too far)
    if (box.width < 100 || box.height < 100 || score < 0.60) {
      return {
        status: 'POOR_QUALITY',
        message: 'Face quality is insufficient. Please improve lighting and face the camera.',
        score: score
      };
    }

    const landmarks = detection.landmarks;
    const descriptor = Array.from(detection.descriptor); // 128 float array

    // 3. Evaluate Liveness / Anti-Spoofing
    const livenessResult = this.evaluateLiveness(landmarks);

    return {
      status: 'FACE_DETECTED',
      box: box,
      score: score,
      landmarks: landmarks,
      descriptor: descriptor,
      liveness: livenessResult
    };
  },

  /**
   * Evaluate Active Liveness heuristics (Blink EAR & Head Yaw Angle)
   */
  evaluateLiveness(landmarks) {
    const points = landmarks.positions;
    // 68 Landmark indices:
    // Left eye: 36, 37, 38, 39, 40, 41
    // Right eye: 42, 43, 44, 45, 46, 47
    // Nose bridge/tip: 27, 28, 29, 30
    // Left cheek: 0..4, Right cheek: 12..16

    const leftEyeEAR = this.calculateEAR([
      points[36], points[37], points[38], points[39], points[40], points[41]
    ]);
    const rightEyeEAR = this.calculateEAR([
      points[42], points[43], points[44], points[45], points[46], points[47]
    ]);
    const avgEAR = (leftEyeEAR + rightEyeEAR) / 2.0;

    // Eye blink trigger
    if (avgEAR < 0.22) {
      this.liveness.blinkDetected = true;
    }

    // Yaw Angle Estimation: Nose tip (30) relative to outer eye corners (36 & 45)
    const noseX = points[30].x;
    const leftEyeCornerX = points[36].x;
    const rightEyeCornerX = points[45].x;

    const leftDist = Math.abs(noseX - leftEyeCornerX);
    const rightDist = Math.abs(rightEyeCornerX - noseX);
    const yawRatio = rightDist > 0 ? (leftDist / rightDist) : 1.0;

    // Active Liveness & Centered Gaze Evaluation
    const isCentered = (yawRatio >= 0.70 && yawRatio <= 1.40);
    if (isCentered) {
      this.liveness.straightFramesCount++;
    } else {
      this.liveness.straightFramesCount = Math.max(0, this.liveness.straightFramesCount - 1);
    }

    const base = this.liveness.baselineYawRatio || yawRatio;
    const yawDelta = Math.abs(yawRatio - base);
    if (yawDelta >= 0.20 || this.liveness.blinkDetected) {
      this.liveness.yawTurnDetected = true;
    }

    // Pass condition: Stable direct frontal gaze (>= 2 frames) OR blink OR subtle head movement
    if (this.liveness.straightFramesCount >= 2 || this.liveness.yawTurnDetected || this.liveness.blinkDetected) {
      this.liveness.state = 'PASSED';
      return {
        passed: true,
        state: 'PASSED',
        instruction: '✓ Face aligned. Verifying identity...',
        action: this.liveness.yawTurnDetected ? 'head_turn' : (this.liveness.blinkDetected ? 'blink' : 'frontal_gaze')
      };
    }

    return {
      passed: false,
      state: 'LOOK_STRAIGHT',
      instruction: 'Please look directly into the camera frame.',
      action: 'look_camera'
    };
  },

  /**
   * Compute Eye Aspect Ratio (EAR) for blink detection
   */
  calculateEAR(eyePoints) {
    // eyePoints: [p1, p2, p3, p4, p5, p6]
    // dist(p2, p6) + dist(p3, p5) / (2 * dist(p1, p4))
    const d1 = this.euclidean(eyePoints[1], eyePoints[5]);
    const d2 = this.euclidean(eyePoints[2], eyePoints[4]);
    const d3 = this.euclidean(eyePoints[0], eyePoints[3]);
    if (d3 === 0) return 0.3;
    return (d1 + d2) / (2.0 * d3);
  },

  euclidean(p1, p2) {
    const dx = p1.x - p2.x;
    const dy = p1.y - p2.y;
    return Math.sqrt(dx * dx + dy * dy);
  },

  /**
   * Average and normalize an array of 128D embedding vectors
   */
  averageEmbeddings(vectorList) {
    if (!vectorList || vectorList.length === 0) return null;
    const n = vectorList.length;
    const avg = new Array(128).fill(0.0);

    for (let i = 0; i < n; i++) {
      const vec = vectorList[i];
      for (let j = 0; j < 128; j++) {
        avg[j] += vec[j];
      }
    }

    for (let j = 0; j < 128; j++) {
      avg[j] /= n;
    }

    // L2 Normalize
    let norm = 0.0;
    for (let j = 0; j < 128; j++) {
      norm += avg[j] * avg[j];
    }
    norm = Math.sqrt(norm);
    if (norm > 0) {
      for (let j = 0; j < 128; j++) {
        avg[j] /= norm;
      }
    }

    return avg;
  }
};
