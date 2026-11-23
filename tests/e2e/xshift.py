"""How far content moved up between two screenshots of the same area: python3 -I xshift.py a.png b.png x,y,w,h [near]
Prints the shift s (pixels, positive = content moved up) for which b's rows equal a's rows s pixels lower,
over the area, or "none". With "near", rows only have to be nearly equal (text drawn at a fraction of a pixel
off, as in a virtual list placed from an estimate). Uses ImageMagick's convert to read the pixels."""
import subprocess, sys

def rows(path, x, y, w, h):
    raw = subprocess.run(["convert", path, "-crop", f"{w}x{h}+{x}+{y}", "+repage", "gray:-"], capture_output=True, check=True).stdout
    return [raw[i * w:(i + 1) * w] for i in range(h)]

x, y, w, h = map(int, sys.argv[3].split(","))
near = len(sys.argv) > 4 and sys.argv[4] == "near"

def same(pairs):
    if not near:
        return all(p == q for p, q in pairs)
    # On average under a twentieth of a grey level per pixel: the same text, a fraction of a pixel off.
    return sum(abs(u - v) for p, q in pairs for u, v in zip(p, q)) <= 0.05 * w * len(pairs)

a, b = rows(sys.argv[1], x, y, w, h), rows(sys.argv[2], x, y, w, h)
for s in sorted(range(-h // 2, h // 2), key=abs):
    pairs = [(a[i + s], b[i]) for i in range(h) if 0 <= i + s < h]
    if len(pairs) >= h // 2 and any(r.strip(r[:1]) for r, _ in pairs) and same(pairs):
        print(s)
        break
else:
    print("none")
