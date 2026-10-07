// A picture to upload, made in memory: a solid-colour PNG of the given size.
//
// The checks used to upload an old logo kept in this directory. Generating one
// means no binary to keep around for it, and any size a check needs.
const zlib = require('zlib');

function crc32(buf) {
  let c = ~0;
  for (const byte of buf) {
    c ^= byte;
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1));
  }
  return ~c >>> 0;
}

function chunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

function fixturePng(width = 256, height = 256, rgb = [255, 165, 0]) {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8; // bit depth
  header[9] = 2; // truecolour RGB

  // Each scanline is a filter byte (0, none) followed by its pixels.
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array(width).fill(rgb).flat())]);
  const pixels = Buffer.concat(Array(height).fill(row));

  return {
    name: 'fixture.png',
    mimeType: 'image/png',
    buffer: Buffer.concat([
      Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
      chunk('IHDR', header),
      chunk('IDAT', zlib.deflateSync(pixels)),
      chunk('IEND', Buffer.alloc(0)),
    ]),
  };
}

module.exports = { fixturePng };
