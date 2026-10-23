// scripts/fix_telematics_loading.js
const fs = require('fs');
const path = require('path');

const filePath = path.resolve('frontend/src/components/Telematics/GISControlTowerMap.tsx');
let content = fs.readFileSync(filePath, 'utf8');

// Hapus state loading dan setLoading agar tidak memicu TS6133
content = content.replace(/const\s+\[loading,\s*setLoading\]\s*=\s*useState\(true\);\r?\n?/, '');
content = content.replace(/finally\s*\{\s*setLoading\(false\);\s*\}/, '');

fs.writeFileSync(filePath, content, 'utf8');
console.log('✅ File GISControlTowerMap.tsx berhasil dibersihkan dari unused loading state!');