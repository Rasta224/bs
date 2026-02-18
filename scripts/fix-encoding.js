import { writeFileSync, mkdirSync, readFileSync, existsSync } from 'fs';
import { dirname, join } from 'path';

const root = '/vercel/share/v0-project';

const pages = {
  'contacts/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/contacts/index.html',
  'faq/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/faq/index.html',
  'list/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/list/index.html',
  'partner/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/partner/index.html',
  'report/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/report/index.html',
  'wiki/help/index.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/wiki/help/index.html',
  'template/bestchange.html': 'https://raw.githubusercontent.com/Rasta224/bs/main/template/bestchange.html',
};

// Simple Windows-1251 to UTF-8 decoder
const win1251Table = new Map();
const cp1251 = '\u0410\u0411\u0412\u0413\u0414\u0415\u0416\u0417\u0418\u0419\u041A\u041B\u041C\u041D\u041E\u041F\u0420\u0421\u0422\u0423\u0424\u0425\u0426\u0427\u0428\u0429\u042A\u042B\u042C\u042D\u042E\u042F\u0430\u0431\u0432\u0433\u0434\u0435\u0436\u0437\u0438\u0439\u043A\u043B\u043C\u043D\u043E\u043F\u0440\u0441\u0442\u0443\u0444\u0445\u0446\u0447\u0448\u0449\u044A\u044B\u044C\u044D\u044E\u044F';
// 0xC0-0xFF -> А-я
for (let i = 0; i < 64; i++) {
  win1251Table.set(0xC0 + i, cp1251.charCodeAt(i));
}
// Additional cp1251 mappings for 0x80-0xBF
const extra = {
  0x80: 0x0402, 0x81: 0x0403, 0x82: 0x201A, 0x83: 0x0453, 0x84: 0x201E, 0x85: 0x2026,
  0x86: 0x2020, 0x87: 0x2021, 0x88: 0x20AC, 0x89: 0x2030, 0x8A: 0x0409, 0x8B: 0x2039,
  0x8C: 0x040A, 0x8D: 0x040C, 0x8E: 0x040B, 0x8F: 0x040F, 0x90: 0x0452, 0x91: 0x2018,
  0x92: 0x2019, 0x93: 0x201C, 0x94: 0x201D, 0x95: 0x2022, 0x96: 0x2013, 0x97: 0x2014,
  0x98: 0x0098, 0x99: 0x2122, 0x9A: 0x0459, 0x9B: 0x203A, 0x9C: 0x045A, 0x9D: 0x045C,
  0x9E: 0x045B, 0x9F: 0x045F, 0xA0: 0x00A0, 0xA1: 0x040E, 0xA2: 0x045E, 0xA3: 0x0408,
  0xA4: 0x00A4, 0xA5: 0x0490, 0xA6: 0x00A6, 0xA7: 0x00A7, 0xA8: 0x0401, 0xA9: 0x00A9,
  0xAA: 0x0404, 0xAB: 0x00AB, 0xAC: 0x00AC, 0xAD: 0x00AD, 0xAE: 0x00AE, 0xAF: 0x0407,
  0xB0: 0x00B0, 0xB1: 0x00B1, 0xB2: 0x0406, 0xB3: 0x0456, 0xB4: 0x0491, 0xB5: 0x00B5,
  0xB6: 0x00B6, 0xB7: 0x00B7, 0xB8: 0x0451, 0xB9: 0x2116, 0xBA: 0x0454, 0xBB: 0x00BB,
  0xBC: 0x0458, 0xBD: 0x0405, 0xBE: 0x0455, 0xBF: 0x0457,
};
for (const [k, v] of Object.entries(extra)) {
  win1251Table.set(Number(k), v);
}

function decodeWin1251(buffer) {
  let result = '';
  for (let i = 0; i < buffer.length; i++) {
    const byte = buffer[i];
    if (byte < 0x80) {
      result += String.fromCharCode(byte);
    } else {
      const mapped = win1251Table.get(byte);
      result += mapped ? String.fromCharCode(mapped) : '\uFFFD';
    }
  }
  return result;
}

function isLikelyWin1251(buffer) {
  // Check if there are bytes in 0xC0-0xFF range that look like Windows-1251 Cyrillic
  let win1251Count = 0;
  let invalidUtf8 = 0;
  for (let i = 0; i < Math.min(buffer.length, 50000); i++) {
    const b = buffer[i];
    if (b >= 0xC0 && b <= 0xFF) win1251Count++;
    if (b >= 0x80 && b <= 0xBF && (i === 0 || buffer[i-1] < 0x80)) invalidUtf8++;
  }
  return win1251Count > 50 || invalidUtf8 > 20;
}

async function main() {
  for (const [local, url] of Object.entries(pages)) {
    console.log(`Downloading ${local}...`);
    try {
      const resp = await fetch(url);
      if (!resp.ok) {
        console.log(`  FAILED: ${resp.status}`);
        continue;
      }
      const buffer = Buffer.from(await resp.arrayBuffer());
      console.log(`  Downloaded ${buffer.length} bytes`);

      let content;
      if (isLikelyWin1251(buffer)) {
        console.log('  Detected Windows-1251, converting to UTF-8...');
        content = decodeWin1251(buffer);
      } else {
        content = buffer.toString('utf-8');
        // Check for replacement chars
        const repCount = (content.match(/\uFFFD/g) || []).length;
        if (repCount > 5) {
          console.log(`  Found ${repCount} broken chars in UTF-8, trying Windows-1251...`);
          content = decodeWin1251(buffer);
        }
      }

      // Replace charset declarations
      content = content.replace(/charset\s*=\s*"?windows-1251"?/gi, 'charset="utf-8"');

      // Count remaining issues
      const remaining = (content.match(/\uFFFD/g) || []).length;
      console.log(`  Replacement chars after fix: ${remaining}`);

      const dest = join(root, local);
      mkdirSync(dirname(dest), { recursive: true });
      writeFileSync(dest, content, 'utf-8');
      console.log(`  Saved to ${dest} (${content.length} chars)`);
    } catch (e) {
      console.log(`  ERROR: ${e.message}`);
    }
  }
  console.log('Done!');
}

main();
