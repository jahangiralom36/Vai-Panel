// Builds assets/vp-app.css for index.php (replaces the Tailwind CDN script).
// Rebuild after changing classes in index.php:
//   npx tailwindcss@3.4.17 -c tools/tailwind.config.js -i tools/tailwind.input.css -o assets/vp-app.css --minify
module.exports = {
  content: ['./index.php'],
  theme: { extend: {} },
  plugins: [],
};
