/**
 * BanquetDesk SPA build.
 * Source of truth: resources/js/{banquetdesk.app.js,combo-pricing.js}
 * Output: public/{banquetdesk.app.js,combo-pricing.js}
 *
 * Usage: npm run build:app
 * Set MINIFY=0 to copy without terser.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { minify } from "terser";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const srcDir = path.join(root, "resources", "js");
const outDir = path.join(root, "public");
const minifyEnabled = process.env.MINIFY !== "0";

const files = ["banquetdesk.app.js", "combo-pricing.js"];

async function buildOne(name) {
  const src = path.join(srcDir, name);
  const dest = path.join(outDir, name);
  if (!fs.existsSync(src)) {
    throw new Error(`Missing source: ${src}\nCreate it or copy from public/${name}`);
  }
  const code = fs.readFileSync(src, "utf8");
  let out = code;
  if (minifyEnabled) {
    const result = await minify(code, {
      compress: false,
      mangle: false,
      format: { comments: false },
    });
    if (result.code) out = result.code;
  }
  fs.writeFileSync(dest, out, "utf8");
  console.log(`OK ${name} → public/${name} (${out.length} bytes${minifyEnabled ? ", terser" : ", copy"})`);
}

for (const f of files) {
  await buildOne(f);
}
console.log("build:app complete");
