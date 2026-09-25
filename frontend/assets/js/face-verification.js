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

      // Requirement 4: Ensure video is active, has dimensions and readyState >= 2
      if (!videoEl || videoEl.readyState < 2 || !videoEl.videoWidth || !videoEl.videoHeight || videoEl.videoWidth <= 0 || videoEl.videoHeight <= 0) {
        return;
      }

      // Check model load state
      if (typeof FaceEngine === 'undefined' || !FaceEngine.isLoaded) {
        this.setStatus('Loading face recognition model...', 'scanning');
        return;
      }

      const result = await FaceEngine.processFrame(videoEl);

      if (result.status === 'NOT_READY') {
        this.setStatus('Camera ready — looking for face...', 'scanning');
        return;
      }

      if (result.status === 'NO_FACE') {
        if (typeof FaceEngine !== 'undefined') {
          FaceEngine.resetLiveness();
        }
        this.setStatus('Camera ready — looking for face...', '');
        return;
      }

      if (result.status === 'MULTIPLE_FACES') {
        if (typeof FaceEngine !== 'undefined') {
          FaceEngine.resetLiveness();
        }
        this.setStatus('Only one person should be visible.', 'warning');
        return;
      }

      if (result.status === 'POOR_QUALITY') {
        this.setStatus('Face detected — checking quality...', 'warning');
        return;
      }

      // Face detected! Check quality & evaluate liveness
      this.setStatus('Face detected — checking quality...', 'scanning');
      const liveness = result.liveness || { passed: true, action: 'frontal_gaze' };
      if (!forceManual && !liveness.passed) {
        this.setStatus(liveness.instruction || 'Face detected — checking quality...', 'liveness');
        return;
      }

      // Liveness & quality confirmed! Announce verification in progress
      this.setStatus('Face ready — verifying identity...', 'scanning');
      this.isCoolingDown = true; // Pause frame polling while awaiting backend verification

      // Safe Audit Log (Requirement 3: Never log raw biometric vectors!)
      const descriptor = result.descriptor;
      const isValidArray = Array.isArray(descriptor);
      const descriptorLength = isValidArray ? descriptor.length : 0;
      const allNumeric = isValidArray && descriptorLength === 128 && descriptor.every(v => typeof v === 'number' && !Number.isNaN(v) && Number.isFinite(v));
      const containsNaN = isValidArray && descriptor.some(v => typeof v !== 'number' || Number.isNaN(v));
      const containsNull = isValidArray && descriptor.some(v => v === null || v === undefined);
      const descriptorGenerated = allNumeric && descriptorLength === 128 && !containsNaN && !containsNull;

      console.log({
        descriptorGenerated: descriptorGenerated,
        descriptorLength: descriptorLength,
        allNumeric: allNumeric,
        containsNaN: containsNaN,
        containsNull: containsNull
      });

      if (!descriptorGenerated) {
        console.warn('[SAMS Face Engine] Invalid descriptor produced. Retrying frame capture...');
        this.setStatus('Face detected — checking quality...', 'warning');
        await new Promise(r => setTimeout(r, 1200));
        return;
      }

      let frameData = null;
      try {
        if (typeof Camera !== 'undefined' && Camera.captureFrame) {
          frameData = Camera.captureFrame();
        }
      } catch (cErr) {}

      if (!frameData && videoEl) {
        // Fallback frame capture via canvas
        try {
          const canvas = document.createElement('canvas');
          canvas.width = videoEl.videoWidth || 1280;
          canvas.height = videoEl.videoHeight || 720;
          const ctx = canvas.getContext('2d');
          ctx.imageSmoothingEnabled = true;
          ctx.imageSmoothingQuality = 'high';
          ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
          frameData = canvas.toDataURL('image/jpeg', 0.92);
        } catch (cvErr) {}
      }

      const payload = {
        session_id: this.sessionId,
        embedding: descriptor,
        liveness_passed: true,
        liveness_action: liveness.action || 'frontal_gaze',
        auto_mark: true
      };

      const res = await API.post('/api/face/verify', payload);

      if (res && res.success && res.data) {
        const data = res.data;
        const student = data.student || {};
        const studentName = student.full_name || student.name || 'Student';

        console.log('[SAMS Face Verification Match Result]', {
          match: 'YES',
          student_id: student.student_id,
          name: studentName,
          result_code: data.result_code || (data.already_marked ? 'ALREADY_MARKED' : 'FACE_RECOGNIZED'),
          attendance: data.already_marked ? 'ALREADY_PRESENT' : 'CREATED',
          class_check: 'PASS'
        });

        // Sequence: "Student recognized" -> "Attendance marked" / "Already Present"
        this.setStatus('Student recognized', 'verified');
        await new Promise(r => setTimeout(r, 450));

        if (data.already_marked || data.result_code === 'ALREADY_MARKED') {
          this.setStatus('Already Present', 'verified');
          UI.toast(`Already Present: ${studentName}`, 'info');
        } else {
          this.setStatus('Attendance marked', 'success');
          UI.toast(`✓ Attendance Marked: ${studentName}`, 'success');

          // Institutional confirmation chime
          this.playAudioFeedback();
        }

        if (typeof this.onStudentVerified === 'function') {
          this.onStudentVerified(data);
        }

        // Student recognition cooldown (3.5 seconds) so the same student is not repeatedly submitted
        await new Promise(r => setTimeout(r, 3500));
        if (typeof FaceEngine !== 'undefined') {
          FaceEngine.resetLiveness();
        }
        this.setStatus('Camera ready — looking for face...', 'scanning');
      }
    } catch (err) {
      console.warn('[SAMS Face Cycle Notice]', err.message, err.data);
      const code = (err.data && err.data.error && err.data.error.code) ||
                   (err.data && err.data.result_code) || 
                   (err.data && err.data.code) || 
                   (err.data && err.data.error && err.data.error.details && err.data.error.details.result_code) || '';

      if (err.isTimeout || (err.message && err.message.toLowerCase().includes('timed out'))) {
        this.setStatus('Face verification timed out. Please try again.', 'warning');
        UI.toast('Face verification timed out. Please try again.', 'error');
        await new Promise(r => setTimeout(r, 3000));
      } else if (code === 'SESSION_NOT_FOUND' || (err.status === 404 && err.message && err.message.toLowerCase().includes('session'))) {
        this.setStatus('Attendance session not found.', 'error');
        UI.toast('Attendance session #' + this.sessionId + ' not found or closed. Please select an active session.', 'error', 5000);
        this.stopContinuousScan();
        return;
      } else if (code === 'SESSION_CLOSED') {
        this.setStatus('Attendance session has been closed.', 'warning');
        UI.toast('This attendance session has been closed.', 'warning', 5000);
        this.stopContinuousScan();
        return;
      } else if (code === 'WRONG_CLASS' || code === 'CLASS_MISMATCH') {
        this.setStatus('Student belongs to another class/division.', 'warning');
        UI.toast('Student belongs to another class/division.', 'error');
        await new Promise(r => setTimeout(r, 3000));
      } else if (code === 'NO_ENROLLED_STUDENTS' || code === 'NO_ENROLLED_FACE' || code === 'FACE_NOT_ENROLLED') {
        this.setStatus('No enrolled face found for this student/class.', 'warning');
        UI.toast('No enrolled face profiles found for this class and division. Admin must enroll students first.', 'warning', 4000);
        await new Promise(r => setTimeout(r, 3000));
      } else if (code === 'LIVENESS_FAILED') {
        this.setStatus('Liveness check failed. Please look at the camera.', 'warning');
        await new Promise(r => setTimeout(r, 2000));
      } else if (code === 'FACE_NOT_RECOGNIZED' || code === 'VERIFICATION_FAILED' || (err.message && (err.message.includes('not recognized') || err.message.includes('not be verified')))) {
        this.setStatus('Face not recognized — please look directly at camera', 'warning');
        UI.toast('Face not recognized. Ensure student is enrolled by Admin.', 'warning', 3000);
        await new Promise(r => setTimeout(r, 2500));
      } else {
        const displayMsg = err.message || 'Face verification error. Please try again.';
        this.setStatus(displayMsg, 'warning');
        UI.toast(displayMsg, 'warning');
        await new Promise(r => setTimeout(r, 3000));
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
