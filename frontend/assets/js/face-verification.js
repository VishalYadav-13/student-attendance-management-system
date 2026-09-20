/**
 * SAMS - Face Verification & Attendance Pipeline Orchestrator
 * Coordinates camera feeds, real-time FaceEngine landmark analysis,
 * active anti-spoofing liveness verification, and secure backend communication.
 */

const FaceVerification = {
  sessionId: null,
  isScanning: false,
  scanTimer: null,
  isCoolingDown: false,
  overlayEl: null,
  statusPillEl: null,
  onStudentVerified: null,
  onStatsUpdated: null,

  init(options = {}) {
    this.sessionId = options.sessionId;
    this.overlayEl = document.querySelector('.face-guide-overlay');
    this.statusPillEl = document.getElementById('camera-status-pill') || document.querySelector('.camera-status-pill');
    this.onStudentVerified = options.onStudentVerified || null;
    this.onStatsUpdated = options.onStatsUpdated || null;

    if (options.sessionId && typeof FaceEngine !== 'undefined') {
      FaceEngine.resetLiveness();
    }
  },

  setStatus(text, stateClass = '', badgeBg = '#6E7F8D') {
    if (!this.statusPillEl) {
      this.statusPillEl = document.getElementById('camera-status-pill') || document.querySelector('.camera-status-pill');
    }
    if (this.statusPillEl) {
      let dotColor = badgeBg;
      if (stateClass === 'success' || stateClass === 'verified') dotColor = '#526B54'; // Vintage muted sage
      else if (stateClass === 'warning' || stateClass === 'spoof') dotColor = '#A56B52'; // Muted terracotta
      else if (stateClass === 'scanning' || stateClass === 'liveness') dotColor = '#6E7F8D'; // Dusty blue

      this.statusPillEl.innerHTML = `
        <span class="status-dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${dotColor};"></span>
        <span>${text}</span>
      `;
    }

    if (this.overlayEl) {
      this.overlayEl.className = `face-guide-overlay ${stateClass}`;
    }
  },

  confirmationBuffer: [],
  REQUIRED_CONFIRMATIONS: 2, // Require 2 consistent frames before marking attendance

  /**
   * Run a single verification evaluation cycle
   * @param {boolean} forceManual - If true, bypasses isScanning check to evaluate immediately
   */
  async runVerificationCycle(forceManual = false) {
    if ((!this.isScanning && !forceManual) || this.isCoolingDown) return;

    try {
      const videoEl = Camera.videoEl || 
                      document.getElementById('webcam-video') || 
                      document.getElementById('face-camera-video') || 
                      document.querySelector('video');

      // Ensure video is active, has dimensions and readyState >= 2
      if (!videoEl || videoEl.readyState < 2 || !videoEl.videoWidth || !videoEl.videoHeight || videoEl.videoWidth <= 0 || videoEl.videoHeight <= 0) {
        return;
      }

      let descriptor = null;
      let liveness = { passed: true, action: 'frontal_gaze' };

      // Optional Client-side pre-filtering with FaceEngine if loaded
      if (typeof FaceEngine !== 'undefined' && FaceEngine.isLoaded) {
        const result = await FaceEngine.processFrame(videoEl);

        if (result.status === 'NOT_READY') {
          this.setStatus('Camera ready — looking for face...', 'scanning');
          return;
        }

        if (result.status === 'NO_FACE') {
          FaceEngine.resetLiveness();
          this.confirmationBuffer = [];
          this.setStatus('No face detected', '');
          return;
        }

        if (result.status === 'MULTIPLE_FACES') {
          FaceEngine.resetLiveness();
          this.confirmationBuffer = [];
          this.setStatus('Please keep only one student in front of the camera', 'warning');
          return;
        }

        if (result.status === 'POOR_QUALITY') {
          this.setStatus('Face detected — checking quality...', 'warning');
          return;
        }

        liveness = result.liveness || { passed: true, action: 'frontal_gaze' };
        if (!forceManual && !liveness.passed) {
          this.setStatus(liveness.instruction || 'Please look naturally toward the camera...', 'liveness');
          return;
        }

        descriptor = result.descriptor;
      }

      // Capture frame snapshot from video element
      let frameData = null;
      try {
        if (typeof Camera !== 'undefined' && Camera.captureFrame) {
          frameData = Camera.captureFrame();
        }
      } catch (cErr) {}

      if (!frameData && videoEl) {
        try {
          const canvas = document.createElement('canvas');
          canvas.width = Math.min(videoEl.videoWidth || 640, 640);
          canvas.height = Math.min(videoEl.videoHeight || 480, 480);
          const ctx = canvas.getContext('2d');
          ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
          frameData = canvas.toDataURL('image/jpeg', 0.82);
        } catch (cvErr) {}
      }

      if (!frameData) {
        return;
      }

      // Multi-Frame Confirmation Protocol:
      // Probe frame with auto_mark = false until REQUIRED_CONFIRMATIONS reached, then auto_mark = true
      const isConfirmationStep = this.confirmationBuffer.length >= (this.REQUIRED_CONFIRMATIONS - 1);
      const shouldAutoMark = isConfirmationStep;

      this.isCoolingDown = true;
      if (this.confirmationBuffer.length > 0) {
        this.setStatus(`Confirming identity (${this.confirmationBuffer.length + 1}/${this.REQUIRED_CONFIRMATIONS})...`, 'scanning');
      } else {
        this.setStatus('Face ready — recognizing identity...', 'scanning');
      }

      const payload = {
        session_id: this.sessionId,
        image: frameData,
        liveness_passed: liveness.passed,
        liveness_action: liveness.action || 'frontal_gaze',
        auto_mark: shouldAutoMark
      };

      if (descriptor && Array.isArray(descriptor) && descriptor.length === 128) {
        payload.embedding = descriptor;
      }

      // Call SAMS Face Recognition Endpoint
      const res = await API.post('/api/face/recognize', payload);

      if (res && res.success && res.data) {
        const data = res.data;
        const student = data.student || {};
        const studentId = data.student_id || student.student_id;
        const studentName = student.full_name || student.name || data.student_name || 'Student';

        // Case A: Student already present in this session
        if (data.status === 'already_present' || data.already_marked) {
          this.confirmationBuffer = [];
          this.setStatus('Already Present', 'verified');
          UI.toast(`Already Present: ${studentName}`, 'info');

          if (typeof this.onStudentVerified === 'function') {
            this.onStudentVerified({ ...data, already_marked: true });
          }

          // Brief cooldown so teacher can move to the next student
          await new Promise(r => setTimeout(r, 2800));
          if (typeof FaceEngine !== 'undefined') FaceEngine.resetLiveness();
          this.setStatus('Camera ready — looking for face...', 'scanning');
          return;
        }

        // Case B: In multi-frame confirmation buffer
        if (!shouldAutoMark) {
          // Verify if this matches current buffer student
          if (this.confirmationBuffer.length === 0 || this.confirmationBuffer[0].student_id === studentId) {
            this.confirmationBuffer.push({ student_id: studentId, student, data });
            this.setStatus(`Recognized ${studentName} — hold steady...`, 'scanning');
            // Brief gap before next confirmation frame
            await new Promise(r => setTimeout(r, 350));
            return;
          } else {
            // Frame mismatch: different face detected, reset buffer
            this.confirmationBuffer = [{ student_id: studentId, student, data }];
            this.setStatus(`Checking face match...`, 'scanning');
            return;
          }
        }

        // Case C: Multi-frame confirmation complete! Identity verified and marked
        this.confirmationBuffer = [];
        this.setStatus('Attendance Marked', 'success');
        UI.toast(`✓ Attendance Marked: ${studentName}`, 'success');

        // Play feedback chime
        this.playAudioFeedback();

        if (typeof this.onStudentVerified === 'function') {
          this.onStudentVerified(data);
        }

        // 3.5s cooldown after marking
        await new Promise(r => setTimeout(r, 3500));
        if (typeof FaceEngine !== 'undefined') FaceEngine.resetLiveness();
        this.setStatus('Camera ready — looking for face...', 'scanning');
      }
    } catch (err) {
      this.confirmationBuffer = [];
      const code = (err.data && err.data.error && err.data.error.code) ||
                   (err.data && err.data.result_code) || 
                   (err.data && err.data.code) || '';

      if (code === 'LOW_CONFIDENCE' || code === 'SIMILARITY_BELOW_THRESHOLD') {
        this.setStatus('Face match confidence too low', 'warning');
        await new Promise(r => setTimeout(r, 1200));
      } else if (code === 'MULTIPLE_FACES') {
        this.setStatus('Please keep only one student in front of the camera', 'warning');
        await new Promise(r => setTimeout(r, 1500));
      } else if (code === 'NO_FACE' || code === 'NO_FACE_DETECTED') {
        this.setStatus('No face detected', '');
        await new Promise(r => setTimeout(r, 800));
      } else if (code === 'SERVICE_UNAVAILABLE' || err.status === 503) {
        this.setStatus('Face recognition service unavailable', 'warning');
        UI.toast('Face recognition service unavailable. Please use manual attendance fallback.', 'error');
        await new Promise(r => setTimeout(r, 3500));
      } else if (code === 'WRONG_CLASS' || code === 'CLASS_RESTRICTION') {
        this.setStatus('Student belongs to another class/division.', 'warning');
        UI.toast('Student belongs to another class/division.', 'warning');
        await new Promise(r => setTimeout(r, 3000));
      } else if (code === 'FACE_NOT_RECOGNIZED') {
        this.setStatus('Face not recognized', 'warning');
        await new Promise(r => setTimeout(r, 1500));
      } else if (code === 'LIVENESS_FAILED') {
        this.setStatus('Liveness verification failed. Please blink or turn slightly.', 'warning');
        await new Promise(r => setTimeout(r, 1800));
      } else {
        const msg = err.message || 'Face recognition in progress...';
        this.setStatus(msg, 'warning');
        await new Promise(r => setTimeout(r, 1500));
      }
    } finally {
      this.isCoolingDown = false;
    }
  },

  /**
   * Start continuous scanning loop (runs every 150ms for responsive tracking)
   */
  startContinuousScan(intervalMs = 150) {
    this.stopContinuousScan();
    this.isScanning = true;
    FaceEngine.resetLiveness();
    this.setStatus('Camera ready — looking for face...', 'scanning');

    const loop = async () => {
      if (!this.isScanning) return;
      await this.runVerificationCycle();
      if (this.isScanning) {
        this.scanTimer = setTimeout(loop, intervalMs);
      }
    };

    loop();
  },

  /**
   * Stop continuous scanning loop
   */
  stopContinuousScan() {
    this.isScanning = false;
    if (this.scanTimer) {
      clearTimeout(this.scanTimer);
      this.scanTimer = null;
    }
    this.isCoolingDown = false;
    this.setStatus('Camera stopped. Click "Start AI Camera" to resume.', '');
  },

  /**
   * Soft institutional confirmation audio chime using Web Audio API
   */
  playAudioFeedback() {
    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      const ctx = new AudioContext();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.exponentialRampToValueAtTime(880.00, ctx.currentTime + 0.12); // A5

      gain.gain.setValueAtTime(0.12, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    } catch (e) {
      // Audio playback is purely optional
    }
  }
};
