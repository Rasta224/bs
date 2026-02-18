import { readFileSync, readdirSync, statSync } from 'fs';
import { join } from 'path';

const root = '/vercel/share/v0-project';
const files = [
  'template/bestchange.html',
  'contacts/index.html',
  'faq/index.html',
  'list/index.html',
  'partner/index.html',
  'report/index.html',
  'wiki/help/index.html',
];

for (const f of files) {
  try {
    const content = readFileSync(join(root, f), 'utf-8');
    const lines = content.split('\n');
    let count = 0;
    for (let i = 0; i < lines.length; i++) {
      // Look for replacement character U+FFFD
      if (lines[i].includes('\uFFFD')) {
        count++;
        if (count <= 3) {
          // Show a snippet around the broken char
          const idx = lines[i].indexOf('\uFFFD');
          const start = Math.max(0, idx - 30);
          const end = Math.min(lines[i].length, idx + 30);
          console.log(`${f}:${i+1} pos ${idx}: ...${lines[i].substring(start, end).replace(/\uFFFD/g, '<<BROKEN>>')}...`);
        }
      }
    }
    console.log(`${f}: ${count} lines with broken chars`);
  } catch(e) {
    console.log(`${f}: ERROR ${e.message}`);
  }
}
