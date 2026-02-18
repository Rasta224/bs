import { readFileSync } from 'fs';

const root = '/vercel/share/v0-project';
const files = [
  'template/bestchange.html',
  'contacts/index.html',
  'report/index.html',
];

for (const f of files) {
  try {
    const buf = readFileSync(`${root}/${f}`);
    console.log(`${f}: size=${buf.length}, first bytes: ${buf.slice(0,20).toString('hex')}`);
    
    // Try reading as utf-8
    const content = buf.toString('utf-8');
    
    // Check for replacement chars
    const matches = content.match(/\uFFFD/g);
    console.log(`${f}: ${matches ? matches.length : 0} replacement chars (U+FFFD)`);
    
    // Check for windows-1251 encoded bytes (0x80-0xFF that aren't valid UTF-8)
    let badBytes = 0;
    for (let i = 0; i < buf.length; i++) {
      if (buf[i] >= 0x80 && buf[i] <= 0xBF) {
        // Check if it's a valid UTF-8 continuation byte
        if (i === 0 || buf[i-1] < 0xC0) {
          badBytes++;
          if (badBytes <= 3) {
            const start = Math.max(0, i - 10);
            const end = Math.min(buf.length, i + 10);
            console.log(`  Bad byte 0x${buf[i].toString(16)} at pos ${i}: hex=${buf.slice(start, end).toString('hex')}`);
          }
        }
      }
    }
    console.log(`${f}: ${badBytes} suspicious bytes`);
    
    // Check charset meta tag
    const charsetMatch = content.match(/<meta[^>]*charset[^>]*/i);
    if (charsetMatch) console.log(`${f}: charset meta: ${charsetMatch[0].substring(0, 80)}`);
    
    console.log('---');
  } catch(e) {
    console.log(`${f}: ERROR ${e.message}`);
  }
}
