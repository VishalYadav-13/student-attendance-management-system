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
      // Prioritize high-definition video constraints (1080p Full HD -> 720p HD)
      let constraints = {
        video: {
          facingMode: this.facingMode,
          width: { ideal: 1920 },
          height: { ideal: 1080 },
          frameRate: { ideal: 30 }
        },
        audio: false
      };

      try {
        this.stream = await navigator.mediaDevices.getUserMedia(constraints);
      } catch (hdErr) {
        console.warn("[SAMS Camera] HD constraint fallback:", hdErr.message);
        // Fallback to 720p HD if 1080p not supported
        constraints = {
          video: {
            facingMode: this.facingMode,
            width: { ideal: 1280 },
            height: { ideal: 720 }
          },
          audio: false
        };
        try {
          this.stream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (subErr) {
          console.warn("[SAMS Camera] Standard fallback:", subErr.message);
          this.stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: this.facingMode },
            audio: false
          });
        }
      }

      this.videoEl.srcObject = this.stream;

      // Ensure video metadata and dimensions are populated before resolving
      await new Promise((resolve) => {
        if (this.videoEl.readyState >= 2 && this.videoEl.videoWidth > 0 && this.videoEl.videoHeight > 0) {
          resolve();
        } else {
          let resolved = false;
          const onMeta = () => {
            if (!resolved && this.videoEl.videoWidth > 0 && this.videoEl.videoHeight > 0) {
              resolved = true;
              this.videoEl.removeEventListener('loadedmetadata', onMeta);
              this.videoEl.removeEventListener('canplay', onMeta);
              resolve();
            }
          };
          this.videoEl.addEventListener('loadedmetadata', onMeta);
          this.videoEl.addEventListener('canplay', onMeta);
          // Safety timeout so initialization never hangs indefinitely
          setTimeout(() => {
            if (!resolved) {
              resolved = true;
              this.videoEl.removeEventListener('loadedmetadata', onMeta);
              this.videoEl.removeEventListener('canplay', onMeta);
              resolve();
            }
          }, 1500);
        }
      });

      try {
        await this.videoEl.play();
      } catch (playErr) {
        console.warn("[SAMS Camera] play() notice:", playErr.message);
      }

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
    if (!this.videoEl || !this.stream || this.videoEl.readyState < 2 || !this.videoEl.videoWidth || !this.videoEl.videoHeight) {
      throw new Error("Camera feed is not ready for capture.");
    }

    const canvas = document.createElement('canvas');
    canvas.width = this.videoEl.videoWidth || 1280;
    canvas.height = this.videoEl.videoHeight || 720;

    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';

    // Mirror the frame horizontally to match standard selfie preview
    if (this.facingMode === 'user') {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }
    ctx.drawImage(this.videoEl, 0, 0, canvas.width, canvas.height);

    return canvas.toDataURL('image/jpeg', 0.92);
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
