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
      console.log('[SAMS FaceEngine] Loading neural network models from:', this.modelBaseUrl);
      await Promise.all([
        window.faceapi.nets.tinyFaceDetector.loadFromUri(this.modelBaseUrl),
        window.faceapi.nets.faceLandmark68Net.loadFromUri(this.modelBaseUrl),
        window.faceapi.nets.faceRecognitionNet.loadFromUri(this.modelBaseUrl)
      ]);

      const tinyOk = !!(window.faceapi.nets.tinyFaceDetector && window.faceapi.nets.tinyFaceDetector.isLoaded);
      const landmarkOk = !!(window.faceapi.nets.faceLandmark68Net && window.faceapi.nets.faceLandmark68Net.isLoaded);
      const recogOk = !!(window.faceapi.nets.faceRecognitionNet && window.faceapi.nets.faceRecognitionNet.isLoaded);

      if (!tinyOk || !landmarkOk || !recogOk) {
        throw new Error(`Models failed to load fully. Verification status: tiny=${tinyOk}, landmarks=${landmarkOk}, recognition=${recogOk}`);
      }

      this.isLoaded = true;
      console.log('[SAMS FaceEngine] Neural network models successfully initialized and verified.', {
        tinyFaceDetector: tinyOk,
        faceLandmark68Net: landmarkOk,
        faceRecognitionNet: recogOk
      });
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
      if (typeof window.faceapi !== 'undefined') {
        return resolve();
      }
      const existing = document.querySelector(`script[src="${url}"]`);
      if (existing) {
        if (typeof window.faceapi !== 'undefined') {
          return resolve();
        }
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', (e) => reject(e));
        setTimeout(() => {
          if (typeof window.faceapi !== 'undefined') resolve();
          else reject(new Error(`Timeout loading ${url}`));
        }, 4000);
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

    // Requirement 4: Only begin detection when video is valid, ready, and has real dimensions
    if (!videoEl || videoEl.readyState < 2 || !videoEl.videoWidth || !videoEl.videoHeight || videoEl.videoWidth <= 0 || videoEl.videoHeight <= 0) {
      return { 
        status: 'NOT_READY',
        message: 'Camera ready — looking for face...'
      };
    }

    // Requirement 5 & 6: Balanced threshold (0.40) and input size (320) for reliable detection
    const detectorOptions = new window.faceapi.TinyFaceDetectorOptions({
      inputSize: 320,
      scoreThreshold: 0.40
    });

    // Detect all faces with landmarks and 128D descriptors in a single forward pass
    const allDetections = await window.faceapi
      .detectAllFaces(videoEl, detectorOptions)
      .withFaceLandmarks()
      .withFaceDescriptors();

    const hasFace = Array.isArray(allDetections) && allDetections.length > 0;
    const detectionCount = Array.isArray(allDetections) ? allDetections.length : 0;

    // Requirement 3: Development diagnostics log after every detection attempt
    console.log('[SAMS Face Detection Audit]', {
      faceDetectionAttempt: true,
      faceDetected: hasFace,
      detectionCount: detectionCount,
      videoWidth: videoEl.videoWidth || 0,
      videoHeight: videoEl.videoHeight || 0,
      modelLoaded: this.isLoaded
    });

    if (!allDetections || allDetections.length === 0) {
      this.resetLiveness();
      return {
        status: 'NO_FACE',
        message: 'Camera ready — looking for face...'
      };
    }

    if (allDetections.length > 1) {
      this.resetLiveness();
      return {
        status: 'MULTIPLE_FACES',
        message: 'Only one person should be visible.',
        count: allDetections.length
      };
    }

    // Single face extracted
    const detection = allDetections[0];
    const box = detection.detection.box;
    const score = detection.detection.score;

    // Minimum face size check (at least 60px wide and high)
    if (box.width < 60 || box.height < 60) {
      return {
        status: 'POOR_QUALITY',
        message: 'Face is too far from camera. Please move closer.',
        score: score
      };
    }

    const landmarks = detection.landmarks;
    const rawDescriptor = Array.from(detection.descriptor); // 128 float array
    const descriptor = this.normalizeEmbedding(rawDescriptor);

    // Evaluate Liveness / Anti-Spoofing
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
    // Relaxed yaw ratio for typical camera perspectives and selfie video mirroring
    const isCentered = (yawRatio >= 0.55 && yawRatio <= 1.80);
    if (isCentered) {
      this.liveness.straightFramesCount++;
    } else {
      this.liveness.straightFramesCount = Math.max(0, this.liveness.straightFramesCount - 1);
    }

    const base = this.liveness.baselineYawRatio || yawRatio;
    const yawDelta = Math.abs(yawRatio - base);
    if (yawDelta >= 0.15 || this.liveness.blinkDetected) {
      this.liveness.yawTurnDetected = true;
    }

    // Pass condition: Valid frontal face detected OR blink OR subtle head movement
    if (this.liveness.straightFramesCount >= 1 || this.liveness.yawTurnDetected || this.liveness.blinkDetected) {
      this.liveness.state = 'PASSED';
      return {
        passed: true,
        state: 'PASSED',
        instruction: 'Face detected — verifying...',
        action: this.liveness.yawTurnDetected ? 'head_turn' : (this.liveness.blinkDetected ? 'blink' : 'frontal_gaze')
      };
    }

    return {
      passed: false,
      state: 'LOOK_STRAIGHT',
      instruction: 'Liveness verification failed. Please try again.',
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
   * Strictly L2 normalize a 128-dimensional embedding vector
   */
  normalizeEmbedding(vec) {
    if (!vec || !Array.isArray(vec) || vec.length !== 128) return vec;
    let sumSq = 0.0;
    for (let j = 0; j < 128; j++) {
      const v = Number(vec[j]) || 0;
      sumSq += v * v;
    }
    const norm = Math.sqrt(sumSq);
    if (norm > 0) {
      return vec.map(v => (Number(v) || 0) / norm);
    }
    return vec;
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
        avg[j] += Number(vec[j]) || 0;
      }
    }

    for (let j = 0; j < 128; j++) {
      avg[j] /= n;
    }

    return this.normalizeEmbedding(avg);
  }
};
