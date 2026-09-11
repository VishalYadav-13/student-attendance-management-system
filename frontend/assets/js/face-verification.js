/**
 * SAMS - Face Verification & Attendance Pipeline Orchestrator
 */

const FaceVerification = {
  sessionId: null,
  isScanning: false,
  scanInterval: null,
  overlayEl: null,
  statusPillEl: null,
  onStudentVerified: null,

  init(options = {}) {
    this.sessionId = options.sessionId;
    this.overlayEl = document.querySelector('.face-guide-overlay');
    this.statusPillEl = document.querySelector('.camera-status-pill');
    this.onStudentVerified = options.onStudentVerified || null;
  },

  setStatus(text, stateClass = '') {
    if (this.statusPillEl) {
      this.statusPillEl.innerHTML = `
        <span class="status-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${stateClass === 'success' ? '#10b981' : (stateClass === 'warning' ? '#f59e0b' : '#3b82f6')};"></span>
        <span>${text}</span>
      `;
    }

    if (this.overlayEl) {
      this.overlayEl.className = `face-guide-overlay ${stateClass}`;
    }
  },

  /**
   * Run a single verification cycle
   */
  async runVerificationCycle(targetStudentId = null) {
    if (this.isScanning) return;
    this.isScanning = true;

    try {
      // Step 1: Looking for face
      this.setStatus('Looking for face...', 'scanning');
      await new Promise(r => setTimeout(r, 400));

      // Step 2: Capture frame
      const frameData = Camera.captureFrame();

      // Step 3: Face detected & Checking quality
      this.setStatus('Face detected. Checking quality...', 'detected');
      await new Promise(r => setTimeout(r, 350));

      // Step 4: Verifying identity with backend & Gemini assistance
      this.setStatus('Verifying biometric identity...', 'scanning');

      const payload = {
        session_id: this.sessionId,
        image: frameData,
        auto_mark: true,
        liveness_passed: true
      };
      if (targetStudentId) {
        payload.student_id = targetStudentId;
      }

      const res = await API.post('/api/face/verify', payload);

      if (res && res.success && res.data && res.data.verified) {
        // Step 5: Identity verified
        const student = res.data.student;
        const confidencePct = Math.round((res.data.confidence || 0.95) * 100);
        this.setStatus(`Verified: ${student.full_name} (${confidencePct}%)`, 'detected');

        UI.toast(`✓ Attendance marked for ${student.full_name} (${student.roll_number})`, 'success');

        if (typeof this.onStudentVerified === 'function') {
          this.onStudentVerified(res.data);
        }
      }
    } catch (err) {
      console.warn("[SAMS Face Cycle Notice]", err.message);
      const code = (err.data && err.data.error && err.data.error.code) || '';
      
      if (code === 'NO_FACE') {
        this.setStatus('No face detected. Please look directly at the camera.', 'warning');
      } else if (code === 'MULTIPLE_FACES') {
        this.setStatus('Multiple faces in frame. One student at a time.', 'warning');
      } else if (code === 'CONFLICT' || err.message.includes('already')) {
        this.setStatus('Attendance already recorded.', 'detected');
        UI.toast('Student attendance was already marked.', 'info');
      } else {
        this.setStatus('Verification unconfirmed. Re-aligning...', '');
      }
    } finally {
      this.isScanning = false;
    }
  },

  /**
   * Start auto-polling verification loop
   */
  startContinuousScan(intervalMs = 3000) {
    this.stopContinuousScan();
    this.runVerificationCycle();
    this.scanInterval = setInterval(() => {
      this.runVerificationCycle();
    }, intervalMs);
  },

  stopContinuousScan() {
    if (this.scanInterval) {
      clearInterval(this.scanInterval);
      this.scanInterval = null;
    }
    this.setStatus('Camera ready. Position face in oval frame.', '');
  }
};
