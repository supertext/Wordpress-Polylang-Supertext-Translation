// movie-kit: records short plugin demo movies in Supertext's look with Playwright + ffmpeg.
// Copy this file (and assets/) into a repo's docs tooling; no dependencies besides playwright and ffmpeg.
//
//   const kit = await MovieKit.start(page, { workDir, assets });   // assets: dir with supertext-logo.svg + fonts/
//   await kit.caption('Open the entry you want to translate');      // caption bar at the bottom
//   await kit.click(locator);                                       // visible cursor + ripple, then click
//   kit.pause(); await longWait(); kit.resume();                   // cut waiting time out of the movie
//   await kit.hold(1500);                                           // keep the current frame on screen
//   await kit.say('Tick German and French, then click Translate with Supertext.', {          // voice + caption;
//     caption: 'Tick <b>German</b> and <b>French</b>, click <b>Translate with Supertext</b>',  // the step stays
//     during: async () => { await kit.click(de); await kit.click(fr); await kit.click(go); } }); // until it's said
//   await kit.finish(outMp4, { system: 'Strapi', repo: 'Strapi-Supertext-Translation', gif: 'demo.gif' });
//
// Install movies: kit.terminal() shows a terminal in the Supertext look and types the real commands from
// docs/INSTALLATION.md (shortened output, never secrets or local URLs); finish(…, { kind: 'install' }) titles the
// intro card "Install Supertext for <System>".
//   await kit.terminal({ title: 'my-project', lines: [{ cmd: 'npm install strapi-supertext-translation' }, { out: 'added 1 package' }] });
//
// Output: 1280x720, 30 fps, H.264 (crf 20, yuv420p, faststart); optional GIF (800 px, 12 fps, silent).
// Voice: Piper (pip install piper-tts) with the voice "alba" (en_GB, University of Edinburgh, CC BY 4.0: credit it
// in the docs). The model (63 MB) is downloaded once into voiceCache (default ~/.cache/supertext-movie) and checked
// by SHA-256. Without Piper or with MOVIE_VOICE=0 the movie is made without sound (captions only).
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';

const W = 1280, H = 720, FPS = 30;
const VOICE = {
  name: 'en_GB-alba-medium',
  base: 'https://huggingface.co/rhasspy/piper-voices/resolve/e7229f7d33a10e145e109b886d661984201ac3c4/en/en_GB/alba/medium/',
  sha256: { onnx: '401369c4a81d09fdd86c32c5c864440811dbdcc66466cde2d64f7133a66ad03b', json: 'aa965a2f02ecced632c2694e1fc72bbff6d65f265fab567ca945918c73dd89f4' },
};
const ENC = ['-c:v', 'libx264', '-crf', '20', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-r', String(FPS)];

function ff(args) {
  const r = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' });
  if (r.status !== 0) throw new Error('ffmpeg failed: ' + args.join(' '));
}
function encodeTo(out, args) { const tmp = out.replace(/\.mp4$/, '.part.mp4'); ff([...args, ...ENC, tmp]); fs.renameSync(tmp, out); }

// Injected into every page: caption bar, cursor dot, click ripple. Supertext colours.
const OVERLAY = `(() => {
  if (window.__stk) return; const s = document.createElement('style');
  s.textContent = '#stk-cap{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:2147483647;background:#003230;color:#f7f5f3;font:600 22px/1.3 Inter,system-ui,sans-serif;padding:12px 26px;border-radius:999px;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:1100px;text-align:center;transition:opacity .25s;pointer-events:none}'
   + '#stk-cap b{color:#78ffaf;font-weight:700}'
   + '#stk-cur{position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;background:rgba(0,50,48,.35);border:3px solid #003230;pointer-events:none;transition:left .45s ease,top .45s ease;left:-50px;top:-50px}'
   + '.stk-rip{position:fixed;z-index:2147483646;width:16px;height:16px;margin:-8px 0 0 -8px;border-radius:50%;border:3px solid #78ffaf;pointer-events:none;animation:stkr .6s ease-out forwards}'
   + '@keyframes stkr{to{transform:scale(4);opacity:0}}';
  // Turbo (e.g. Contao 6) swaps <body> without a page load: put the overlay back when it is gone.
  const add = () => { document.head.appendChild(s);
    const c = document.createElement('div'); c.id = 'stk-cap'; c.style.opacity = 0; document.body.appendChild(c);
    const k = document.createElement('div'); k.id = 'stk-cur'; document.body.appendChild(k); };
  if (document.body) add(); else document.addEventListener('DOMContentLoaded', add);
  const ensure = () => { if (document.body && !document.getElementById('stk-cur')) add(); else if (!s.isConnected) document.head.appendChild(s); };
  window.__stk = {
    caption(t) { ensure(); const c = document.getElementById('stk-cap'); if (!c) return; c.innerHTML = t || ''; c.style.opacity = t ? 1 : 0; },
    move(x, y) { ensure(); const k = document.getElementById('stk-cur'); if (k) { k.style.left = x + 'px'; k.style.top = y + 'px'; } },
    ripple(x, y) { ensure(); const r = document.createElement('div'); r.className = 'stk-rip'; r.style.left = x + 'px'; r.style.top = y + 'px'; document.body.appendChild(r); setTimeout(() => r.remove(), 700); },
  }; })();`;

export class MovieKit {
  static async start(page, { workDir, assets, voiceCache }) {
    const kit = new MovieKit(page, workDir, assets);
    kit.voiceModel = await MovieKit.voiceModel(voiceCache);
    await page.setViewportSize({ width: W, height: H });
    await page.addInitScript(OVERLAY);
    await page.evaluate(OVERLAY).catch(() => {});
    kit.cdp = await page.context().newCDPSession(page);
    kit.cdp.on('Page.screencastFrame', async (f) => {
      if (!kit.paused) kit.frames.push({ t: f.metadata.timestamp, data: f.data });
      kit.cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
    });
    await kit.cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: W, maxHeight: H, everyNthFrame: 1 });
    return kit;
  }
  constructor(page, workDir, assets) {
    this.page = page; this.workDir = workDir; this.assets = assets; this.frames = []; this.segments = []; this.paused = false;
    this.caption_ = ''; this.cursor = { x: W / 2, y: H / 2 }; this.voiceN = 0;
    fs.mkdirSync(workDir, { recursive: true });
  }
  // Re-applies overlay state after navigations.
  async sync() { await this.page.evaluate(([t, x, y]) => { window.__stk?.caption(t); window.__stk?.move(x, y); }, [this.caption_, this.cursor.x, this.cursor.y]).catch(() => {}); }
  async caption(text, ms = 0) { this.caption_ = text; await this.sync(); if (ms) await this.hold(ms); }
  async moveTo(locator) {
    await locator.scrollIntoViewIfNeeded(); const b = await locator.boundingBox();
    if (!b) return; this.cursor = { x: b.x + b.width / 2, y: b.y + b.height / 2 }; await this.sync(); await this.page.waitForTimeout(550);
  }
  async click(locator, opts) {
    await this.moveTo(locator);
    await this.page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [this.cursor.x, this.cursor.y]);
    await locator.click(opts); await this.page.waitForTimeout(300); await this.sync();
  }
  async type(locator, text) { await this.click(locator); await locator.pressSequentially(text, { delay: 45 }); }
  async hold(ms) { await this.page.waitForTimeout(ms); }
  // Waiting time is cut: frames are dropped while paused; a cut marker keeps timing honest elsewhere.
  pause() { this.paused = true; this.cut_ = { cut: true, at: Date.now() / 1000 }; this.frames.push(this.cut_); }
  // Frames can arrive after resume() although they were taken while paused (speech generation blocks the event
  // loop): the cut lasts until resume(), so those are dropped too.
  resume() { this.paused = false; if (this.cut_) this.cut_.until = Date.now() / 1000; }

  // The voice model, downloaded once and checked; null = no voice (no Piper, MOVIE_VOICE=0, or download failed).
  static async voiceModel(cache) {
    if (process.env.MOVIE_VOICE === '0') return null;
    if (spawnSync('python3', ['-c', 'import piper'], { stdio: 'ignore' }).status !== 0) { console.warn('movie-kit: piper-tts not installed, no voice'); return null; }
    const dir = cache || path.join(os.homedir(), '.cache', 'supertext-movie'); fs.mkdirSync(dir, { recursive: true });
    for (const [ext, file] of [['onnx', `${VOICE.name}.onnx`], ['json', `${VOICE.name}.onnx.json`]]) {
      const f = path.join(dir, file);
      const ok = () => fs.existsSync(f) && crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex') === VOICE.sha256[ext];
      if (!ok()) {
        const res = await fetch(VOICE.base + file); if (!res.ok) { console.warn(`movie-kit: voice download failed (${res.status}), no voice`); return null; }
        fs.writeFileSync(f, Buffer.from(await res.arrayBuffer()));
        if (!ok()) { fs.rmSync(f); throw new Error(`movie-kit: ${file} doesn't match its SHA-256`); }
      }
    }
    return path.join(dir, `${VOICE.name}.onnx`);
  }
  // Speech for one line: WAV file and its length in seconds (null without a voice).
  speak(text) {
    if (!this.voiceModel) return null;
    const wav = path.join(this.workDir, `voice-${String(this.voiceN++).padStart(2, '0')}.wav`);
    const r = spawnSync('python3', ['-m', 'piper', '-m', this.voiceModel, '-f', wav, '--sentence-silence', '0.25'], { input: text, stdio: ['pipe', 'ignore', 'inherit'] });
    if (r.status !== 0) throw new Error('piper failed');
    const d = parseFloat(spawnSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', wav]).stdout.toString());
    return { wav, d };
  }
  // Says a line (and shows its caption), runs `during` meanwhile, and holds until the line is finished.
  // Without a voice it holds about as long as the line takes to read.
  async say(text, { caption, during, after = 400 } = {}) {
    // Making the speech takes a moment: that time is cut, so no silent gap appears before the line.
    const wasPaused = this.paused; if (!wasPaused) this.pause();
    const v = this.speak(text);
    if (!wasPaused) this.resume();
    const started = Date.now();
    if (v) this.frames.push({ voice: v.wav, at: started / 1000 });
    await this.caption(caption ?? text);
    if (during) await during();
    const need = (v ? v.d * 1000 : Math.max(1800, text.length * 55)) + after;
    const left = need - (Date.now() - started); if (left > 0) await this.hold(left);
  }

  // A terminal window (recorded like any page): commands are typed, output lines appear. Back to the CMS with page.goto().
  async terminal({ title = 'Terminal', lines = [] }) {
    const font = (f) => `data:font/woff2;base64,${fs.readFileSync(path.join(this.assets, 'fonts', f)).toString('base64')}`;
    await this.page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>
      @font-face{font-family:Inter;font-weight:100 900;src:url(${font('inter-latin-wght-normal.woff2')}) format('woff2')}
      *{margin:0;padding:0;box-sizing:border-box} body{width:${W}px;height:${H}px;background:#f7f5f3;font-family:Inter,sans-serif;display:flex;align-items:flex-start;justify-content:center;padding-top:56px}
      .w{width:1080px;height:520px;background:#00201f;border-radius:14px;box-shadow:0 18px 60px rgba(0,50,48,.35);overflow:hidden;display:flex;flex-direction:column}
      .t{background:#003230;color:#f7f5f3;font-size:16px;font-weight:600;padding:12px 18px;display:flex;gap:8px;align-items:center}
      .t i{width:12px;height:12px;border-radius:50%;background:#f7f5f3;opacity:.35;display:inline-block} .t span{margin-left:12px;opacity:.85}
      pre{flex:1;padding:22px 26px;color:#e8f0ee;font:500 21px/1.55 ui-monospace,'DejaVu Sans Mono',monospace;white-space:pre-wrap;word-break:break-all}
      .p{color:#78ffaf} .o{color:#e8f0ee;opacity:.7} .c::after{content:'▋';color:#78ffaf;animation:b 1s steps(1) infinite} @keyframes b{50%{opacity:0}}
    </style></head><body><div class="w"><div class="t"><i></i><i></i><i></i><span></span></div><pre id="x"></pre></div></body></html>`);
    await this.page.evaluate((t) => { document.querySelector('.t span').textContent = t; }, title);
    await this.page.evaluate(OVERLAY).catch(() => {}); await this.sync();
    await this.page.evaluate(() => document.fonts.ready);
    for (const l of lines) {
      if (l.cmd !== undefined) {
        await this.page.evaluate(() => { const x = document.getElementById('x'); x.querySelector('.c')?.classList.remove('c');
          const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p);
          const c = document.createElement('span'); c.className = 'c'; x.appendChild(c); });
        for (const ch of l.cmd) { await this.page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch; }, ch); await this.page.waitForTimeout(38); }
        await this.page.waitForTimeout(450);
        await this.page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n'); });
      } else {
        await this.page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s); }, l.out ?? '');
        await this.page.waitForTimeout(l.wait ?? 180);
      }
    }
    await this.page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c); });
  }

  async renderCard(kind, { system, repo, movie }, seconds = 2.8) {
    const font = (f) => `data:font/woff2;base64,${fs.readFileSync(path.join(this.assets, 'fonts', f)).toString('base64')}`;
    const logo = `data:image/svg+xml;base64,${fs.readFileSync(path.join(this.assets, 'supertext-logo.svg')).toString('base64')}`;
    const body = kind === 'intro' && movie === 'install'
      ? `<div class="k">Install and set up</div><h1>${system}</h1><p>Supertext AI translation for ${system} in a few minutes.</p>`
      : kind === 'intro'
      ? `<div class="k">AI translation for</div><h1>${system}</h1><p>Translate with Supertext, right inside ${system}.</p>`
      : `<div class="k">Get the plugin</div><h1 class="s">github.com/Supertext/${repo}</h1><p>No Supertext account yet? Create one at supertext.com.</p>`;
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>
      @font-face{font-family:Inter;font-weight:100 900;src:url(${font('inter-latin-wght-normal.woff2')}) format('woff2')}
      *{margin:0;padding:0;box-sizing:border-box} body{width:${W}px;height:${H}px;background:#003230;color:#f7f5f3;font-family:Inter,sans-serif;display:flex;flex-direction:column;justify-content:center;padding:0 96px}
      img{position:absolute;top:64px;left:96px;height:44px} .k{font-size:34px;font-weight:600;opacity:.85}
      h1{font-size:96px;font-weight:800;color:#78ffaf;letter-spacing:-2px;line-height:1.05;margin-top:6px} h1.s{font-size:48px;letter-spacing:-.5px}
      p{font-size:28px;margin-top:22px;opacity:.85} .u{position:absolute;bottom:56px;left:96px;font-size:22px;opacity:.6}
    </style></head><body><img src="${logo}" alt=""><div>${body}</div><div class="u">supertext.com</div></body></html>`;
    const ctx = await this.page.context().browser().newContext({ viewport: { width: W, height: H } }); const p = await ctx.newPage();
    await p.setContent(html, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    const png = path.join(this.workDir, `${kind}.png`); await p.screenshot({ path: png }); await ctx.close();
    const mp4 = path.join(this.workDir, `${kind}.mp4`);
    encodeTo(mp4, ['-framerate', String(FPS), '-i', png, '-vf', `format=yuv420p,loop=-1:1,trim=duration=${seconds.toFixed(2)},fade=in:d=0.4,fade=out:st=${(seconds - 0.4).toFixed(2)}:d=0.4`]);
    return mp4;
  }

  async buildScreen() {
    const stopped = Date.now() / 1000; await this.cdp.send('Page.stopScreencast');
    const dir = path.join(this.workDir, 'frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir);
    // Durations from timestamps; a cut drops the gap; the screencast only sends frames on change, so long gaps are held.
    const list = [], marks = []; let prev = null, n = 0, pending = [], cutAt = 0;
    for (const f of this.frames) {
      // A cut ends the picture shown at that moment (it stays as long as it was really on screen), then jumps.
      if (f.cut) { cutAt = f.until ?? f.at; if (prev && !prev.cut) { prev.cut = true; prev.d = Math.min(Math.max(f.at - prev.t, 1 / FPS), 10) + 0.2; } continue; }
      // A voice line starts with the next picture (its caption appearing): same clock as the frames.
      if (f.voice) { pending.push(f); continue; }
      // Frames are delivered late sometimes: one taken before a cut belongs to the cut-out time.
      if (f.t < cutAt) continue;
      // Faster than the frame rate: the newer picture replaces the last one, so timing stays real time.
      if (prev && !prev.cut && f.t - prev.t < 1 / FPS) { fs.writeFileSync(prev.file, Buffer.from(f.data, 'base64')); continue; }
      const file = path.join(dir, `${String(n++).padStart(6, '0')}.jpg`); fs.writeFileSync(file, Buffer.from(f.data, 'base64'));
      if (prev && !prev.cut) prev.d = Math.min(Math.max(f.t - prev.t, 1 / FPS), 10);
      prev = { file, t: f.t, d: 1.0 }; list.push(prev);
      for (const v of pending.filter((v) => f.t >= v.at - 0.05)) marks.push({ voice: v.voice, entry: prev });
      pending = pending.filter((v) => f.t < v.at - 0.05);
    }
    if (!list.length) throw new Error('no frames recorded');
    const last = list.at(-1); if (!last.cut) last.d = Math.min(Math.max(stopped - last.t, 1 / FPS), 10);
    const txt = list.map((e) => `file '${e.file}'\nduration ${e.d.toFixed(4)}`).join('\n') + `\nfile '${list.at(-1).file}'\n`;
    const concat = path.join(this.workDir, 'frames.txt'); fs.writeFileSync(concat, txt);
    // Movie time of each voice line.
    let acc = 0; for (const e of list) { e.start = acc; acc += e.d; }
    this.voices = marks.map((m) => ({ wav: m.voice, at: m.entry.start }));
    const mp4 = path.join(this.workDir, 'screen.mp4');
    encodeTo(mp4, ['-f', 'concat', '-safe', '0', '-i', concat, '-vf', `scale=${W}:${H}:force_original_aspect_ratio=decrease,pad=${W}:${H}:(ow-iw)/2:(oh-ih)/2:color=0xf7f5f3,format=yuv420p,fade=in:d=0.3`, '-fps_mode', 'cfr']);
    return mp4;
  }

  // kind: 'demo' (default) or 'install' (intro card and line "Install and set up …").
  async finish(out, { system, repo, gif, introLine, outroLine, kind = 'demo' }) {
    const screen = await this.buildScreen();
    const vi = this.speak(introLine ?? (kind === 'install' ? `How to install and set up Supertext for ${system}.` : `AI translation for ${system}, with Supertext.`));
    const vo = this.speak(outroLine ?? `Get the plugin on GitHub. And if you don't have a Supertext account yet, create one at supertext.com.`);
    const introS = Math.max(2.8, vi ? vi.d + 1.2 : 0), outroS = Math.max(2.8, vo ? vo.d + 1.4 : 0);
    const intro = await this.renderCard('intro', { system, repo, movie: kind }, introS);
    const outro = await this.renderCard('outro', { system, repo }, outroS);
    const screenS = parseFloat(spawnSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', screen]).stdout.toString());
    const list = path.join(this.workDir, 'parts.txt');
    fs.writeFileSync(list, [intro, screen, outro].map((f) => `file '${f}'`).join('\n') + '\n');
    fs.mkdirSync(path.dirname(out), { recursive: true });
    const tmp = out.replace(/\.mp4$/, '.part.mp4');
    const silent = path.join(this.workDir, 'silent.mp4');
    ff(['-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', silent]);
    const lines = [];
    if (vi) lines.push({ wav: vi.wav, at: 0.5 });
    for (const v of this.voices || []) lines.push({ wav: v.wav, at: introS + v.at });
    if (vo) lines.push({ wav: vo.wav, at: introS + screenS + 0.5 });
    if (lines.length) {
      const inputs = lines.flatMap((l) => ['-i', l.wav]);
      const delays = lines.map((l, i) => `[${i + 1}:a]adelay=${Math.round(l.at * 1000)}:all=1[a${i}]`).join(';');
      const mix = `${delays};${lines.map((_, i) => `[a${i}]`).join('')}amix=inputs=${lines.length}:normalize=0,loudnorm=I=-16:TP=-1.5,aresample=48000,apad[a]`;
      ff(['-i', silent, ...inputs, '-filter_complex', mix, '-map', '0:v', '-map', '[a]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', tmp]);
    } else ff(['-i', silent, '-c', 'copy', '-movflags', '+faststart', tmp]);
    fs.renameSync(tmp, out);
    if (gif) {
      const pal = path.join(this.workDir, 'pal.png');
      ff(['-i', out, '-vf', 'fps=12,scale=800:-1:flags=lanczos,palettegen=stats_mode=diff', pal]);
      ff(['-i', out, '-i', pal, '-lavfi', 'fps=12,scale=800:-1:flags=lanczos[x];[x][1:v]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle', gif]);
    }
    return out;
  }
}
