// Substituir a função initCrop no ui.js
// Cole isso no final do ui.js ou substitua a função initCrop

function initCrop(containerId, imgSrc, callback) {
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  container.style.cssText = 'position:relative;width:100%;height:320px;overflow:hidden;border-radius:8px;background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'20\' height=\'20\'%3E%3Crect width=\'10\' height=\'10\' fill=\'%23ccc\'/%3E%3Crect x=\'10\' y=\'10\' width=\'10\' height=\'10\' fill=\'%23ccc\'/%3E%3Crect x=\'10\' width=\'10\' height=\'10\' fill=\'%23fff\'/%3E%3Crect y=\'10\' width=\'10\' height=\'10\' fill=\'%23fff\'/%3E%3C/svg%3E")';

  const img = document.createElement('img');
  img.src = imgSrc;
  img.style.cssText = 'position:absolute;transform-origin:0 0;cursor:grab;user-select:none;-webkit-user-drag:none;max-width:none;';
  img.draggable = false;

  // Overlay com buraco circular
  const overlay = document.createElement('div');
  overlay.style.cssText = `
    position:absolute;inset:0;pointer-events:none;z-index:2;
    background: radial-gradient(circle at center, transparent 45%, rgba(0,0,0,0.55) 45%);
  `;

  // Borda do círculo
  const ring = document.createElement('div');
  ring.style.cssText = `
    position:absolute;z-index:3;pointer-events:none;
    top:50%;left:50%;transform:translate(-50%,-50%);
    width:90%;aspect-ratio:1;border-radius:50%;
    border:2px solid rgba(255,255,255,0.7);
  `;

  container.appendChild(img);
  container.appendChild(overlay);
  container.appendChild(ring);

  let scale = 1, ox = 0, oy = 0, dragging = false, lastX = 0, lastY = 0;

  img.onload = () => {
    const cw = container.clientWidth, ch = container.clientHeight;
    const circleSize = Math.min(cw, ch) * 0.9;
    const minScale = Math.max(circleSize / img.naturalWidth, circleSize / img.naturalHeight);
    scale = minScale * 1.05;
    ox = (cw - img.naturalWidth * scale) / 2;
    oy = (ch - img.naturalHeight * scale) / 2;
    apply();
  };

  function apply() {
    img.style.width = img.naturalWidth * scale + 'px';
    img.style.height = img.naturalHeight * scale + 'px';
    img.style.left = ox + 'px';
    img.style.top = oy + 'px';
  }

  container.addEventListener('mousedown', e => { if (e.button !== 0) return; dragging=true; lastX=e.clientX; lastY=e.clientY; e.preventDefault(); });
  window.addEventListener('mousemove', e => { if (!dragging) return; ox+=e.clientX-lastX; oy+=e.clientY-lastY; lastX=e.clientX; lastY=e.clientY; apply(); });
  window.addEventListener('mouseup', () => dragging=false);

  container.addEventListener('touchstart', e => { dragging=true; lastX=e.touches[0].clientX; lastY=e.touches[0].clientY; e.preventDefault(); }, {passive:false});
  container.addEventListener('touchmove', e => { if (!dragging) return; ox+=e.touches[0].clientX-lastX; oy+=e.touches[0].clientY-lastY; lastX=e.touches[0].clientX; lastY=e.touches[0].clientY; apply(); e.preventDefault(); }, {passive:false});
  container.addEventListener('touchend', () => dragging=false);

  container.addEventListener('wheel', e => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.1 : 0.9;
    const cx = container.clientWidth / 2, cy = container.clientHeight / 2;
    ox = cx - (cx - ox) * factor;
    oy = cy - (cy - oy) * factor;
    scale *= factor;
    apply();
  }, {passive:false});

  container._exportCrop = () => {
    const cw = container.clientWidth, ch = container.clientHeight;
    const circleSize = Math.min(cw, ch) * 0.9;
    const cropX = (cw - circleSize) / 2, cropY = (ch - circleSize) / 2;
    const ratio = 400 / circleSize;
    const canvas = document.createElement('canvas');
    canvas.width = 400; canvas.height = 400;
    const ctx = canvas.getContext('2d');
    // Círculo de recorte
    ctx.beginPath();
    ctx.arc(200, 200, 200, 0, Math.PI * 2);
    ctx.clip();
    ctx.drawImage(img,
      -(ox - cropX) * ratio, -(oy - cropY) * ratio,
      img.naturalWidth * scale * ratio,
      img.naturalHeight * scale * ratio
    );
    canvas.toBlob(blob => callback(blob), 'image/png', 0.92);
  };
}

window.initCrop = initCrop;
