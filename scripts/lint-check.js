import { execSync } from 'child_process';

try {
  const result1 = execSync('php -l includes/html-processor.php 2>&1').toString();
  console.log('html-processor.php:', result1.trim());
} catch (e) {
  console.log('html-processor.php ERROR:', e.stdout?.toString() || e.message);
}

try {
  const result2 = execSync('php -l index.php 2>&1').toString();
  console.log('index.php:', result2.trim());
} catch (e) {
  console.log('index.php ERROR:', e.stdout?.toString() || e.message);
}
