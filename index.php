<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Face Recognition</title>
  <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: sans-serif; background: #0f172a; color: #e2e8f0; padding: 16px; }
    h2 { margin: 0 0 12px; font-size: 18px; }
    .cam { position: relative; width: 100%; max-width: 480px; margin: 0 auto; border-radius: 16px; overflow: hidden; background: #000; }
    video { width: 100%; display: block; transform: scaleX(-1); } /* efek cermin */
    canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
    .panel { max-width: 480px; margin: 12px auto 0; display: grid; gap: 8px; }
    input, button { padding: 12px; border-radius: 10px; border: 0; font-size: 15px; }
    input { background: #1e293b; color: #fff; }
    button { background: #2563eb; color: #fff; font-weight: 600; }
    button.danger { background: #475569; }
    button:disabled { opacity: .5; }
    #status { font-size: 14px; color: #94a3b8; text-align: center; }
    #list { font-size: 13px; color: #cbd5e1; }
  </style>
</head>
<body>
  <h2>Face Recognition</h2>

  <div class="cam">
    <video id="video" autoplay muted playsinline></video>
    <canvas id="overlay"></canvas>
  </div>

  <div class="panel">
    <div id="status">Memuat model AI...</div>
    <input id="nama" placeholder="Nama, contoh: Firdaus Eko Nuryanto">
    <button id="btnDaftar" disabled>Daftarkan Wajah</button>
    <button id="btnHapus" class="danger">Hapus Semua Data Wajah</button>
    <div id="list"></div>
  </div>

<script>
const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
const THRESHOLD = 0.5;        // makin kecil makin ketat
const STORAGE_KEY = 'faces';

const video   = document.getElementById('video');
const canvas  = document.getElementById('overlay');
const ctx     = canvas.getContext('2d');
const statusEl = document.getElementById('status');
const btnDaftar = document.getElementById('btnDaftar');
const inputNama = document.getElementById('nama');

const detectorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
let matcher = null;

// ---------- Simpan / muat data wajah ----------
function loadFaces() {
  try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || []; }
  catch { return []; }
}

function saveFaces(faces) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(faces));
}

function buildMatcher() {
  const faces = loadFaces();
  document.getElementById('list').innerHTML = faces.length
    ? 'Terdaftar: ' + faces.map(f => `<b>${f.label}</b>`).join(', ')
    : 'Belum ada wajah terdaftar.';

  matcher = faces.length
    ? new faceapi.FaceMatcher(
        faces.map(f => new faceapi.LabeledFaceDescriptors(
          f.label, f.descriptors.map(d => new Float32Array(d))
        )),
        THRESHOLD
      )
    : null;
}

// ---------- Kamera depan ----------
async function startCamera() {
  const stream = await navigator.mediaDevices.getUserMedia({
    video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
    audio: false
  });
  video.srcObject = stream;
  await new Promise(r => video.onloadedmetadata = r);
  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
}

// ---------- Gambar kotak + nama ----------
function drawLabel(box, text, color) {
  // video dicerminkan, jadi koordinat X juga dibalik
  const x = canvas.width - box.x - box.width;
  ctx.strokeStyle = color;
  ctx.lineWidth = 3;
  ctx.strokeRect(x, box.y, box.width, box.height);

  ctx.font = 'bold 18px sans-serif';
  const w = ctx.measureText(text).width + 12;
  ctx.fillStyle = color;
  ctx.fillRect(x, box.y - 28, w, 28);
  ctx.fillStyle = '#fff';
  ctx.fillText(text, x + 6, box.y - 8);
}

// ---------- Loop deteksi live ----------
async function loop() {
  const results = await faceapi
    .detectAllFaces(video, detectorOptions)
    .withFaceLandmarks()
    .withFaceDescriptors();

  ctx.clearRect(0, 0, canvas.width, canvas.height);

  for (const r of results) {
    const box = r.detection.box;
    if (matcher) {
      const best = matcher.findBestMatch(r.descriptor);
      if (best.label !== 'unknown') {
        const persen = Math.round((1 - best.distance) * 100);
        drawLabel(box, `${best.label} (${persen}%)`, '#16a34a');
        continue;
      }
    }
    drawLabel(box, 'Wajah tidak dikenali', '#dc2626');
  }

  statusEl.textContent = results.length ? `${results.length} wajah terdeteksi` : 'Arahkan wajah ke kamera';
  requestAnimationFrame(loop);
}

// ---------- Daftarkan wajah (ambil 5 sampel) ----------
btnDaftar.onclick = async () => {
  const nama = inputNama.value.trim();
  if (!nama) return alert('Isi nama dulu');

  btnDaftar.disabled = true;
  const samples = [];

  for (let i = 1; i <= 5; i++) {
    statusEl.textContent = `Mengambil sampel ${i}/5... gerakkan kepala sedikit`;
    const det = await faceapi
      .detectSingleFace(video, detectorOptions)
      .withFaceLandmarks()
      .withFaceDescriptor();
    if (det) samples.push(Array.from(det.descriptor));
    await new Promise(r => setTimeout(r, 400));
  }

  if (samples.length < 3) {
    alert('Wajah kurang jelas. Pastikan cahaya cukup dan wajah di tengah.');
  } else {
    const faces = loadFaces().filter(f => f.label !== nama);
    faces.push({ label: nama, descriptors: samples });
    saveFaces(faces);
    buildMatcher();
    inputNama.value = '';
    alert(`Wajah "${nama}" berhasil didaftarkan (${samples.length} sampel)`);
  }
  btnDaftar.disabled = false;
};

document.getElementById('btnHapus').onclick = () => {
  if (confirm('Hapus semua data wajah?')) {
    localStorage.removeItem(STORAGE_KEY);
    buildMatcher();
  }
};

// ---------- Start ----------
(async () => {
  try {
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
      faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
      faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
    ]);
    await startCamera();
    buildMatcher();
    btnDaftar.disabled = false;
    loop();
  } catch (e) {
    statusEl.textContent = 'Error: ' + e.message;
  }
})();
</script>
</body>
</html>