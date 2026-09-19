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
    this.statusPillEl = document.querySelector('.camera-status-pill');
    this.onStudentVerified = options.onStudentVerified || null;
    this.onStatsUpdated = options.onStatsUpdated || null;

    if (options.sessionId) {
      FaceEngine.resetLiveness();
    }
  },

  setStatus(text, stateClass = '', badgeBg = '#6E7F8D') {
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

  /**
   * Run a single verification evaluation cycle
   */
  async runVerificationCycle() {
    if (!this.isScanning || this.isCoolingDown) return;

    try {
      const videoEl = Camera.videoEl;
      if (!videoEl || videoEl.readyState !== 4) {
        return;
      }

      // Process live frame via client-side neural network
      const result = await FaceEngine.processFrame(videoEl);

      if (result.status === 'NOT_READY') {
        return;
      }

      if (result.status === 'NO_FACE') {
        this.setStatus('Face not detected. Please position your face inside the frame.', '');
        return;
      }

      if (result.status === 'MULTIPLE_FACES') {
        this.setStatus('Multiple faces detected. Only one person should be visible.', 'warning');
        return;
      }

      if (result.status === 'POOR_QUALITY') {
        this.setStatus('Face quality is insufficient. Please improve lighting and face the camera.', 'warning');
        return;
      }

      // Face is detected! Check liveness progress
      const liveness = result.liveness;
      if (!liveness.passed) {
        this.setStatus(liveness.instruction, 'liveness');
        return;
      }

      // Liveness verified! Proceed to verify biometric identity against session class
      this.setStatus('✓ Liveness confirmed. Verifying identity...', 'scanning');
      this.isCoolingDown = true; // Pause frames while waiting for backend confirmation

      let frameData = null;
      try {
        if (typeof Camera !== 'undefined' && Camera.captureFrame) {
          frameData = Camera.captureFrame();
        }
      } catch (cErr) {
        // Fallback gracefully
      }

      const payload = {
        session_id: this.sessionId,
        embedding: result.descriptor,
        image: frameData,
        liveness_passed: true,
        liveness_action: liveness.action,
        auto_mark: true
      };

      const res = await API.post('/api/face/verify', payload);

      if (res && res.success && res.data) {
        const data = res.data;
        const student = data.student;

        if (data.already_marked) {
          this.setStatus(`✓ Recognized: ${student.full_name} — Attendance already recorded.`, 'verified');
          UI.toast(`Notice: Attendance for ${student.full_name} was already recorded.`, 'info');
        } else {
          this.setStatus(`✓ VERIFIED: ${student.full_name} (${student.roll_number})`, 'success');
          UI.toast(`✓ Attendance marked for ${student.full_name} (${student.roll_number})`, 'success');

          // Optional subtle institutional confirmation beep
          this.playAudioFeedback();
        }

        if (typeof this.onStudentVerified === 'function') {
          this.onStudentVerified(data);
        }

        // Student recognition cooldown (3 seconds for next student to approach)
        await new Promise(r => setTimeout(r, 2800));
      }
    } catch (err) {
      console.warn('[SAMS Face Cycle Notice]', err.message);
      const code = (err.data && err.data.result_code) || (err.data && err.data.error && err.data.error.code) || '';

      if (code === 'NO_ENROLLED_STUDENTS') {
        this.setStatus('No enrolled face profiles found for this class.', 'warning');
        await new Promise(r => setTimeout(r, 2000));
      } else if (code === 'WRONG_CLASS') {
        this.setStatus('Student is not enrolled in this class.', 'warning');
        UI.toast('Student is not enrolled in this class.', 'error');
        await new Promise(r => setTimeout(r, 2000));
      } else if (code === 'LIVENESS_FAILED') {
        this.setStatus('Liveness check failed. Please look straight and turn head slightly.', 'warning');
        await new Promise(r => setTimeout(r, 1500));
      } else if (code === 'VERIFICATION_FAILED' || err.message.includes('not be verified')) {
        this.setStatus('Face could not be verified.', 'warning');
        await new Promise(r => setTimeout(r, 1800));
      } else {
        this.setStatus('Looking for face...', '');
      }
    } finally {
      FaceEngine.resetLiveness();
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
    this.setStatus('Camera ready. Please look at the camera.', 'scanning');

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
    this.setStatus('Camera ready. Position face in oval frame.', '');
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
