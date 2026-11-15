/**
 * Pure TypeScript offline QR Code (Model 2, Byte mode, EC Level L) SVG path renderer.
 * Supports payloads up to ~271 UTF-8 bytes (Versions 1-10), covering any TOTP otpauth:// URI
 * with zero third-party runtime dependencies or CDN calls.
 */

interface QrVersionSpec {
  version: number;
  dataCodewords: number;
  ecPerBlock: number;
  blocksGroup1: number;
  dataPerBlock1: number;
  blocksGroup2: number;
  dataPerBlock2: number;
  alignment: number[];
}

// EC Level L table for Versions 1..10 (ISO/IEC 18004)
const QR_SPECS: QrVersionSpec[] = [
  { version: 1, dataCodewords: 19, ecPerBlock: 7, blocksGroup1: 1, dataPerBlock1: 19, blocksGroup2: 0, dataPerBlock2: 0, alignment: [] },
  { version: 2, dataCodewords: 34, ecPerBlock: 10, blocksGroup1: 1, dataPerBlock1: 34, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 18] },
  { version: 3, dataCodewords: 55, ecPerBlock: 15, blocksGroup1: 1, dataPerBlock1: 55, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 22] },
  { version: 4, dataCodewords: 80, ecPerBlock: 20, blocksGroup1: 1, dataPerBlock1: 80, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 26] },
  { version: 5, dataCodewords: 108, ecPerBlock: 26, blocksGroup1: 1, dataPerBlock1: 108, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 30] },
  { version: 6, dataCodewords: 136, ecPerBlock: 18, blocksGroup1: 2, dataPerBlock1: 68, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 34] },
  { version: 7, dataCodewords: 156, ecPerBlock: 20, blocksGroup1: 2, dataPerBlock1: 78, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 22, 38] },
  { version: 8, dataCodewords: 194, ecPerBlock: 24, blocksGroup1: 2, dataPerBlock1: 97, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 24, 42] },
  { version: 9, dataCodewords: 232, ecPerBlock: 30, blocksGroup1: 2, dataPerBlock1: 116, blocksGroup2: 0, dataPerBlock2: 0, alignment: [6, 26, 46] },
  { version: 10, dataCodewords: 274, ecPerBlock: 18, blocksGroup1: 2, dataPerBlock1: 68, blocksGroup2: 2, dataPerBlock2: 69, alignment: [6, 28, 50] },
];

const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);
(() => {
  let x = 1;
  for (let i = 0; i < 255; i++) {
    GF_EXP[i] = x;
    GF_LOG[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
  for (let i = 255; i < 512; i++) {
    GF_EXP[i] = GF_EXP[i - 255];
  }
})();

function gfMul(a: number, b: number): number {
  if (a === 0 || b === 0) return 0;
  return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

function rsGeneratorPoly(degree: number): Uint8Array {
  let poly: number[] = [1];
  for (let i = 0; i < degree; i++) {
    const next = new Array<number>(poly.length + 1).fill(0);
    const factor = GF_EXP[i];
    for (let j = 0; j < poly.length; j++) {
      next[j] ^= poly[j];
      next[j + 1] ^= gfMul(poly[j], factor);
    }
    poly = next;
  }
  return Uint8Array.from(poly);
}

function rsEncode(data: Uint8Array, ecCount: number): Uint8Array {
  const gen = rsGeneratorPoly(ecCount);
  const res = new Uint8Array(data.length + ecCount);
  res.set(data);
  for (let i = 0; i < data.length; i++) {
    const coef = res[i];
    if (coef !== 0) {
      for (let j = 1; j < gen.length; j++) {
        res[i + j] ^= gfMul(gen[j], coef);
      }
    }
  }
  return res.slice(data.length);
}

function bchFormat(maskPattern: number): number {
  // EC Level L = 01 (binary) -> bits: (01 << 3) | maskPattern
  const data = (0b01 << 3) | maskPattern;
  let rem = data << 10;
  for (let i = 14; i >= 10; i--) {
    if ((rem >> i) & 1) rem ^= 0x537 << (i - 10);
  }
  return ((data << 10) | rem) ^ 0x5412;
}

function bchVersion(version: number): number {
  let rem = version << 12;
  for (let i = 17; i >= 12; i--) {
    if ((rem >> i) & 1) rem ^= 0x1f25 << (i - 12);
  }
  return (version << 12) | rem;
}

export function encodeQrMatrix(text: string): { size: number; modules: boolean[][] } {
  const bytes = new TextEncoder().encode(text);
  const spec = QR_SPECS.find((s) => {
    const overheadBits = 4 + (s.version >= 10 ? 16 : 8);
    return Math.ceil((overheadBits + bytes.length * 8 + 4) / 8) <= s.dataCodewords;
  });
  if (!spec) {
    throw new Error("QR payload too long for offline encoder (max ~270 bytes)");
  }

  // 1. Build bit buffer (Byte mode = 0100)
  const bits: number[] = [];
  const pushBits = (val: number, len: number) => {
    for (let i = len - 1; i >= 0; i--) bits.push((val >> i) & 1);
  };
  pushBits(0b0100, 4);
  pushBits(bytes.length, spec.version >= 10 ? 16 : 8);
  for (const b of bytes) pushBits(b, 8);
  const totalBits = spec.dataCodewords * 8;
  const terminator = Math.min(4, totalBits - bits.length);
  for (let i = 0; i < terminator; i++) bits.push(0);
  while (bits.length % 8 !== 0) bits.push(0);

  const dataBytes = new Uint8Array(spec.dataCodewords);
  for (let i = 0; i < bits.length / 8; i++) {
    let byteVal = 0;
    for (let j = 0; j < 8; j++) byteVal = (byteVal << 1) | bits[i * 8 + j];
    dataBytes[i] = byteVal;
  }
  let padIdx = bits.length / 8;
  const padBytes = [0xec, 0x11];
  for (let toggle = 0; padIdx < spec.dataCodewords; toggle++) {
    dataBytes[padIdx++] = padBytes[toggle % 2];
  }

  // 2. Error correction blocks & interleaving
  const dataBlocks: Uint8Array[] = [];
  const ecBlocks: Uint8Array[] = [];
  let offset = 0;
  for (let b = 0; b < spec.blocksGroup1; b++) {
    const blk = dataBytes.slice(offset, offset + spec.dataPerBlock1);
    offset += spec.dataPerBlock1;
    dataBlocks.push(blk);
    ecBlocks.push(rsEncode(blk, spec.ecPerBlock));
  }
  for (let b = 0; b < spec.blocksGroup2; b++) {
    const blk = dataBytes.slice(offset, offset + spec.dataPerBlock2);
    offset += spec.dataPerBlock2;
    dataBlocks.push(blk);
    ecBlocks.push(rsEncode(blk, spec.ecPerBlock));
  }

  const finalBits: number[] = [];
  const maxDataLen = Math.max(spec.dataPerBlock1, spec.dataPerBlock2);
  for (let i = 0; i < maxDataLen; i++) {
    for (const blk of dataBlocks) {
      if (i < blk.length) {
        for (let b = 7; b >= 0; b--) finalBits.push((blk[i] >> b) & 1);
      }
    }
  }
  for (let i = 0; i < spec.ecPerBlock; i++) {
    for (const ec of ecBlocks) {
      for (let b = 7; b >= 0; b--) finalBits.push((ec[i] >> b) & 1);
    }
  }

  // 3. Matrix setup
  const size = spec.version * 4 + 17;
  const grid: number[][] = Array.from({ length: size }, () => Array<number>(size).fill(-1));
  const reserved: boolean[][] = Array.from({ length: size }, () => Array<boolean>(size).fill(false));

  const setFixed = (r: number, c: number, dark: boolean) => {
    if (r >= 0 && r < size && c >= 0 && c < size) {
      grid[r][c] = dark ? 1 : 0;
      reserved[r][c] = true;
    }
  };

  const drawFinder = (tr: number, tc: number) => {
    for (let r = -1; r <= 7; r++) {
      for (let c = -1; c <= 7; c++) {
        const rr = tr + r;
        const cc = tc + c;
        if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
        const inOuter = r >= 0 && r <= 6 && c >= 0 && c <= 6;
        const onBorder = r === 0 || r === 6 || c === 0 || c === 6;
        const inCore = r >= 2 && r <= 4 && c >= 2 && c <= 4;
        setFixed(rr, cc, inOuter && (onBorder || inCore));
      }
    }
  };

  drawFinder(0, 0);
  drawFinder(0, size - 7);
  drawFinder(size - 7, 0);

  // Alignment patterns
  const align = spec.alignment;
  for (const rCenter of align) {
    for (const cCenter of align) {
      if (reserved[rCenter][cCenter]) continue;
      for (let r = -2; r <= 2; r++) {
        for (let c = -2; c <= 2; c++) {
          const dark = Math.max(Math.abs(r), Math.abs(c)) === 2 || (r === 0 && c === 0);
          setFixed(rCenter + r, cCenter + c, dark);
        }
      }
    }
  }

  // Timing patterns & dark module
  for (let i = 8; i < size - 8; i++) {
    if (!reserved[6][i]) setFixed(6, i, i % 2 === 0);
    if (!reserved[i][6]) setFixed(i, 6, i % 2 === 0);
  }
  setFixed(size - 8, 8, true);

  // Reserve format info & optional version info areas
  for (let i = 0; i <= 8; i++) {
    if (i !== 6) {
      reserved[8][i] = true;
      reserved[i][8] = true;
    }
  }
  for (let i = 0; i < 8; i++) {
    reserved[8][size - 1 - i] = true;
    if (i < 7) reserved[size - 1 - i][8] = true;
  }
  if (spec.version >= 7) {
    const vBits = bchVersion(spec.version);
    for (let i = 0; i < 18; i++) {
      const bit = Boolean((vBits >> i) & 1);
      const r = Math.floor(i / 3);
      const c = size - 11 + (i % 3);
      setFixed(r, c, bit);
      setFixed(c, r, bit);
    }
  }

  // Place data bits (zigzag)
  let bitIdx = 0;
  let up = true;
  for (let col = size - 1; col > 0; col -= 2) {
    if (col === 6) col--;
    for (let i = 0; i < size; i++) {
      const row = up ? size - 1 - i : i;
      for (const c of [col, col - 1]) {
        if (!reserved[row][c]) {
          grid[row][c] = bitIdx < finalBits.length ? finalBits[bitIdx++] : 0;
        }
      }
    }
    up = !up;
  }

  // Pick mask 0..7 with lowest penalty (here evaluate all 8 and choose best)
  const masks: Array<(r: number, c: number) => boolean> = [
    (r, c) => (r + c) % 2 === 0,
    (r) => r % 2 === 0,
    (_r, c) => c % 3 === 0,
    (r, c) => (r + c) % 3 === 0,
    (r, c) => (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0,
    (r, c) => ((r * c) % 2) + ((r * c) % 3) === 0,
    (r, c) => (((r * c) % 2) + ((r * c) % 3)) % 2 === 0,
    (r, c) => (((r + c) % 2) + ((r * c) % 3)) % 2 === 0,
  ];

  let bestMask = 0;
  let bestScore = Infinity;
  let bestModules: boolean[][] = [];

  for (let m = 0; m < masks.length; m++) {
    const cand = grid.map((row, r) =>
      row.map((val, c) => {
        if (reserved[r][c]) return val === 1;
        return (val === 1) !== masks[m](r, c);
      })
    );

    const fmt = bchFormat(m);
    for (let i = 0; i < 6; i++) {
      const b = Boolean((fmt >> i) & 1);
      cand[8][size - 1 - i] = b;
      cand[i][8] = Boolean((fmt >> (14 - i)) & 1);
    }
    cand[8][7] = Boolean((fmt >> 6) & 1);
    cand[8][8] = Boolean((fmt >> 7) & 1);
    cand[7][8] = Boolean((fmt >> 8) & 1);
    cand[8][size - 7] = Boolean((fmt >> 6) & 1);
    cand[8][size - 8] = Boolean((fmt >> 7) & 1);
    for (let i = 0; i < 6; i++) {
      cand[8][5 - i] = Boolean((fmt >> (9 + i)) & 1);
    }
    for (let i = 0; i < 7; i++) {
      cand[size - 1 - i][8] = Boolean((fmt >> i) & 1);
    }

    // Simple penalty evaluation: consecutive modules + finder-like runs
    let score = 0;
    for (let r = 0; r < size; r++) {
      let run = 1;
      for (let c = 1; c < size; c++) {
        if (cand[r][c] === cand[r][c - 1]) run++;
        else {
          if (run >= 5) score += 3 + (run - 5);
          run = 1;
        }
      }
    }
    for (let c = 0; c < size; c++) {
      let run = 1;
      for (let r = 1; r < size; r++) {
        if (cand[r][c] === cand[r - 1][c]) run++;
        else {
          if (run >= 5) score += 3 + (run - 5);
          run = 1;
        }
      }
    }

    if (score < bestScore) {
      bestScore = score;
      bestMask = m;
      bestModules = cand;
    }
  }

  void bestMask;
  return { size, modules: bestModules };
}

export function qrSvgPath(text: string): { size: number; viewBox: string; path: string } {
  const { size, modules } = encodeQrMatrix(text);
  const quiet = 2;
  const total = size + quiet * 2;
  let d = "";
  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      if (modules[r][c]) {
        d += `M${c + quiet},${r + quiet}h1v1h-1z`;
      }
    }
  }
  return {
    size,
    viewBox: `0 0 ${total} ${total}`,
    path: d,
  };
}
