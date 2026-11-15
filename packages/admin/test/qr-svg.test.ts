import { describe, expect, test } from "bun:test";
import { encodeQrMatrix, qrSvgPath } from "../src/lib/qr-svg";

describe("Admin QR SVG generator", () => {
  test("qrSvgPath and encodeQrMatrix deterministically encode otpauth URIs with quiet zone", () => {
    const uri = "otpauth://totp/Lumora:owner%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Lumora&digits=6&period=30";
    const matrix = encodeQrMatrix(uri);
    expect(matrix.size).toBeGreaterThanOrEqual(21);
    expect(matrix.modules.length).toBe(matrix.size);

    const svg = qrSvgPath(uri);
    expect(svg.size).toBe(matrix.size);
    expect(svg.viewBox).toBe(`0 0 ${svg.size + 4} ${svg.size + 4}`);
    expect(svg.path.startsWith("M")).toBe(true);
    expect(svg.path.length).toBeGreaterThan(100);
    expect(qrSvgPath(uri)).toEqual(svg);
  });
});
