// scripts/inspect_nav.js
const fs = require('fs');
const path = require('path');

const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
const content = fs.readFileSync(appPath, 'utf8');
const lines = content.split('\n');

console.log('Mencari deklarasi array menu navigasi di App.tsx...\n');

let found = false;
lines.forEach((line, index) => {
  if (!found && (line.includes('orders') || line.includes('/orders')) && (line.includes('to:') || line.includes('to =') || line.includes('label:'))) {
    found = true;
    const start = Math.max(0, index - 8);
    const end = Math.min(lines.length, index + 25);
    console.log(`Ditemukan di sekitar baris ${index + 1}:\n`);
    console.log(lines.slice(start, end).join('\n'));
  }
});

if (!found) {
  // Alternatif cari keyword NavLink atau navigation
  lines.forEach((line, index) => {
    if (line.includes('navigation') || line.includes('navItems') || line.includes('sections')) {
      console.log(`Baris ${index + 1}: ${line}`);
    }
  });
}