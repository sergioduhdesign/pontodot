# Acha onde está o rosto no trecho: imprime o centro horizontal (0..1) mais estável. Sem rosto → 0.5.
import sys, cv2, json
v, ini, fim = sys.argv[1], float(sys.argv[2]), float(sys.argv[3])
cap = cv2.VideoCapture(v); det = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
if det.empty(): print(0.5); sys.exit(0)
xs = []; t = ini; passo = max(0.5, (fim - ini) / 40)
while t < fim:
    cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000); ok, f = cap.read()
    if not ok: break
    h, w = f.shape[:2]; k = 480.0 / h; g = cv2.cvtColor(cv2.resize(f, (int(w * k), 480)), cv2.COLOR_BGR2GRAY)
    r = det.detectMultiScale(g, 1.2, 5, minSize=(40, 40))
    if len(r):
        x, y, a, b = max(r, key=lambda q: q[2] * q[3]); xs.append((x + a / 2) / g.shape[1])
    t += passo
xs.sort(); print(round(xs[len(xs) // 2], 3) if len(xs) >= 3 else 0.5)
