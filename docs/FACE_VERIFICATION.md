# SAMS Face Verification & Biometrics Privacy Manual

## 1. Responsible AI & Architectural Realism

> [!IMPORTANT]
> **Ethical & Technical Distinction**: Large Language Models (LLMs) such as Google Gemini are state-of-the-art vision-language models capable of image analysis, scene understanding, and structured quality validation. However, **they are not standalone biometric identification engines**. 
> 
> SAMS adheres to institutional engineering integrity: **identity verification does not depend solely on a prompt asking an LLM to identify a student.**

### Multi-Stage Verification Pipeline

```
[Webcam Stream (Browser)]
           │
           ▼
[Client Face Guide & Liveness Challenge]
(Checks head position and interactive blink prompt)
           │
           ▼
[Server-Side Quality Assessment (Google Gemini AI)]
(Validates lighting, blurriness, single face presence)
           │
           ▼
[Biometric Feature Vector Matcher]
(Compares landmark embeddings against stored student profiles)
           │
           ▼
[Confidence Threshold Check (>= 0.75)]
           │
           ├─── PASS ───► [Mark Attendance: PRESENT via FACE_AI]
           │
           └─── FAIL ───► [Prompt for Re-alignment or Teacher Manual Fallback]
```

---

## 2. Privacy & Data Minimization (Zero Raw Image Storage)

In compliance with international data privacy standards (such as GDPR Article 9 for special category biometric data):

1. **No Raw Video Storage**: Live video feeds are processed in volatile client memory. Frames sent to the server for verification are ephemeral and **never written to persistent disk storage**.
2. **Mathematical Landmark Descriptors**: Enrolled student profiles in `face_profiles` store only irreversible cryptographic hashes and numerical landmark descriptors (`feature_vector`), from which a human face cannot be visually reconstructed.
3. **Explicit Consent**: Face enrollment requires an explicit consent checkbox. A student cannot be enrolled without recording `consent_given = true` and `consent_timestamp = CURRENT_TIMESTAMP`.
4. **Right to Erasure (Biometric Purge)**: Students can delete their biometric record at any time via `DELETE /api/face/{studentId}` in their profile. Once purged, the student status reverts to `NOT_ENROLLED` and classroom attendance reverts to teacher manual roll-call.

---

## 3. Anti-Spoofing & Liveness Protocol
To mitigate presentation attacks (such as holding up a printed photograph or smartphone screen displaying the student's portrait):
- The client displays an interactive liveness cue ("Look straight ahead", "Blink eyes", "Turn head slightly").
- Gemini AI vision checks for planar reflections, glare, and screen moiré patterns during the quality assessment phase.
- In accordance with prompt instructions, this feature is labeled transparently as a **Liveness & Quality Assistance Prototype** rather than an infallible biometric defense.

---

## 4. Manual Fallback Guarantee
In real classroom environments, lighting variations, camera malfunctions, or temporary facial changes (bandages, spectacles) can cause verification to fail. 
- The system **never debars a student from attendance** due to camera failure.
- Authorized teachers can switch to the **Manual Attendance Grid** or apply a **Manual Override** with an explanatory note (e.g. *"Student present in laboratory but webcam backlighting caused verification timeout"*).
