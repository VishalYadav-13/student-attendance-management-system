/**
 * SAMS - Camera & Video Frame Capture Engine
 * Manages explicit permission requests, webcam stream lifecycle, and canvas frame capture.
 */

const Camera = {
  stream: null,
  facingMode: 'user',
  videoEl: null,

  /**
   * Start webcam stream and attach to <video> element
   * Explicitly informs user and requires browser permission grant
   */
  async start(videoElement, facingMode = 'user') {
    this.videoEl = videoElement;
    this.facingMode = facingMode;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      throw new Error("Webcam access is not supported by your browser or requires HTTPS.");
    }

    // Stop existing stream if active
    this.stop();

    try {
      const constraints = {
        video: {
          facingMode: this.facingMode,
          width: { ideal: 640 },
          height: { ideal: 480 }
        },
        audio: false
      };

      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
      this.videoEl.srcObject = this.stream;
      await this.videoEl.play();

      return true;
    } catch (err) {
      console.error("[SAMS Camera Error]", err);
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        throw new Error("Camera permission was denied. Please allow camera access in your browser settings.");
      } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
        throw new Error("No camera device was detected on your system.");
      }
      throw new Error(`Unable to access camera: ${err.message}`);
    }
  },

  /**
   * Capture a single frame from the live video stream as a Base64 JPEG
   */
  captureFrame() {
    if (!this.videoEl || !this.stream || this.videoEl.readyState !== 4) {
      throw new Error("Camera feed is not ready for capture.");
    }

    const canvas = document.createElement('canvas');
    canvas.width = this.videoEl.videoWidth || 640;
    canvas.height = this.videoEl.videoHeight || 480;

    const ctx = canvas.getContext('2d');
    // Mirror the frame horizontally to match standard selfie preview
    if (this.facingMode === 'user') {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }
    ctx.drawImage(this.videoEl, 0, 0, canvas.width, canvas.height);

    return canvas.toDataURL('image/jpeg', 0.88);
  },

  /**
   * Toggle between front and back cameras on mobile devices
   */
  async switchCamera() {
    const nextMode = this.facingMode === 'user' ? 'environment' : 'user';
    if (this.videoEl) {
      return await this.start(this.videoEl, nextMode);
    }
  },

  /**
   * Stop webcam stream and release hardware
   */
  stop() {
    if (this.stream) {
      this.stream.getTracks().forEach(track => track.stop());
      this.stream = null;
    }
    if (this.videoEl) {
      this.videoEl.srcObject = null;
    }
  }
};
