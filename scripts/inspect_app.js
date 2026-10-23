// scripts/inspect_app.js
const fs = require('fs');
const path = require('path');

// 1. Cek isi dan state navigasi di App.tsx
const appPath = path.resolve(process.cwd(), 'frontend/src/App.tsx');
const appContent = fs.readFileSync(appPath, 'utf8');

console.log('--- 1. IMPORTS & STATES DI App.tsx ---');
const stateMatches = appContent.match(/const\s+\[[a-zA-Z0-9_]+,\s*[a-zA-Z0-9_]+\]\s*=\s*useState[^;]+;/g);
if (stateMatches) {
  console.log('State variables:', stateMatches);
}

// 2. Cek tab atau views yang ada di App.tsx
console.log('\n--- 2. TABS/VIEWS DI App.tsx ---');
const viewMatches = appContent.match(/['"][a-zA-Z0-9_-]+['"]\s*===?\s*[a-zA-Z0-9_.]+|[a-zA-Z0-9_.]+\s*===?\s*['"][a-zA-Z0-9_-]+['"]/g);
if (viewMatches) {
  console.log('View comparison matches:', viewMatches.slice(0, 10));
}

// 3. Cek apa yang dirender di workflow.test.tsx
const testPath = path.resolve(process.cwd(), 'frontend/src/test/workflow.test.tsx');
if (fs.existsSync(testPath)) {
  const testContent = fs.readFileSync(testPath, 'utf8');
  console.log('\n--- 3. KOMPONEN DI workflow.test.tsx ---');
  const testRenderMatches = testContent.match(/render\([^)]+\)/g);
  console.log('Render calls:', testRenderMatches);
}