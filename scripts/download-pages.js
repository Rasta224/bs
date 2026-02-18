import { writeFileSync, mkdirSync, existsSync } from 'fs';

const pages = [
  { path: 'contacts/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/contacts/index.html' },
  { path: 'faq/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/faq/index.html' },
  { path: 'list/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/list/index.html' },
  { path: 'partner/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/partner/index.html' },
  { path: 'report/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/report/index.html' },
  { path: 'wiki/help/index.html', url: 'https://raw.githubusercontent.com/Rasta224/bs/main/wiki/help/index.html' },
];

const baseDir = '/vercel/share/v0-project';

async function downloadAll() {
  for (const page of pages) {
    const fullPath = `${baseDir}/${page.path}`;
    const dir = fullPath.substring(0, fullPath.lastIndexOf('/'));
    
    if (!existsSync(dir)) {
      mkdirSync(dir, { recursive: true });
    }
    
    console.log(`Downloading ${page.url}...`);
    try {
      const resp = await fetch(page.url);
      if (!resp.ok) {
        console.error(`Failed to download ${page.path}: ${resp.status} ${resp.statusText}`);
        continue;
      }
      const buffer = Buffer.from(await resp.arrayBuffer());
      writeFileSync(fullPath, buffer);
      console.log(`Saved ${page.path} (${buffer.length} bytes)`);
    } catch (err) {
      console.error(`Error downloading ${page.path}:`, err.message);
    }
  }
  console.log('Done!');
}

downloadAll();
