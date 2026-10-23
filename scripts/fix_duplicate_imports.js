// scripts/fix_duplicate_imports.js
const fs = require('fs');
const path = require('path');

const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
let content = fs.readFileSync(appPath, 'utf8');

const lines = content.split(/\r?\n/);
const seenImports = new Set();
const cleanLines = [];

for (const line of lines) {
  const trimmed = line.trim();
  if (trimmed.startsWith('import ') && trimmed.includes('./components/')) {
    if (seenImports.has(trimmed)) {
      continue; // Lewati jika duplikat
    }
    seenImports.add(trimmed);
  }
  cleanLines.push(line);
}

fs.writeFileSync(appPath, cleanLines.join('\n'), 'utf8');
console.log('✅ Duplikasi import berhasil dibersihkan dari App.tsx!');