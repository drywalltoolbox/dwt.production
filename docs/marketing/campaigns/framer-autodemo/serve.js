#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { stripVTControlCharacters } from 'node:util';

const VERSION = "5.0.2";
const argv = process.argv.slice(2);
const options = { port: process.env.PORT || '3000', host: '127.0.0.1', dir: path.dirname(fileURLToPath(import.meta.url)), open: false, spa: true, cache: '0', quiet: false, logs: false, ui: true, color: true };
const help = [
  'framerexport · preview v' + VERSION, '',
  '  node serve.js [directory] [options]',
  '  fexport serve [directory] [options]', '',
  '  -p, --port <number>   Port (default: PORT or 3000; 0 chooses a free port)',
  '  -H, --host <address>  Bind address (default: 127.0.0.1)',
  '  -d, --dir <path>      Directory to serve (default: this exported folder)',
  '  -o, --open            Open the site in your browser',
  '      --no-spa          Return 404 for unknown routes',
  '      --cache <seconds> Cache duration (default: 0, always fresh)',
  '      --logs            Start with detailed request logs',
  '      --no-ui           Print ordinary console output',
  '  -q, --quiet           Print only the server URL',
  '      --no-color        Disable colors',
  '  -h, --help            Show this help',
  '  -v, --version         Show the version', '',
  '  Example: node serve.js -p 8080 --open',
  '  Terminal: n Mode · o Open · ? Help · ↑↓ Scroll · End Follow · q Stop', '',
].join('\n');

function fail(message) {
  console.error('Preview: ' + message);
  process.exitCode = 1;
}

async function main() {
  let directorySet = false;
  let positional = false;
  const valued = { '-p': 'port', '--port': 'port', '-H': 'host', '--host': 'host', '-d': 'dir', '--dir': 'dir', '--cache': 'cache' };
  const boolean = { '-o': 'open', '--open': 'open', '--logs': 'logs', '-q': 'quiet', '--quiet': 'quiet' };
  for (let i = 0; i < argv.length; i++) {
    const argument = argv[i];
    if (!positional && ['--help','-h'].includes(argument)) { console.log(help); return; }
    if (!positional && ['--version','-v'].includes(argument)) { console.log(VERSION); return; }
    if (!positional && argument === '--') { positional = true; continue; }
    if (!positional && argument.startsWith('-')) {
      const equals = argument.indexOf('=');
      const flag = equals < 0 ? argument : argument.slice(0, equals);
      if (valued[flag]) {
        const value = equals < 0 ? argv[++i] : argument.slice(equals + 1);
        if (!value || (equals < 0 && value.startsWith('-'))) throw new Error('Missing value for ' + flag + '. See --help.');
        if (valued[flag] === 'dir' && directorySet) throw new Error('Specify only one directory.');
        options[valued[flag]] = value;
        if (valued[flag] === 'dir') directorySet = true;
      } else if (boolean[flag] && equals < 0) options[boolean[flag]] = true;
      else if (argument === '--no-spa') options.spa = false;
      else if (argument === '--no-ui') options.ui = false;
      else if (argument === '--no-color') options.color = false;
      else throw new Error('Unknown option: ' + argument + '. See --help.');
    } else {
      if (directorySet) throw new Error('Specify only one directory.');
      options.dir = argument; directorySet = true;
    }
  }
  if (!/^\d+$/.test(options.port) || Number(options.port) > 65535) throw new Error('Port must be an integer between 0 and 65535.');
  if (!/^\d+$/.test(options.cache) || !Number.isSafeInteger(Number(options.cache)) || Number(options.cache) > 31536000) throw new Error('Cache duration must be an integer between 0 and 31536000 seconds.');
  if (!/^[a-zA-Z0-9._:-]+$/.test(options.host)) throw new Error('Invalid host. Use an IP address or hostname.');
  const port = Number(options.port);
  const cache = Number(options.cache);
  let root;
  try { root = fs.realpathSync(path.resolve(options.dir)); if (!fs.statSync(root).isDirectory()) throw new Error(); }
  catch { throw new Error('Directory not found: ' + options.dir); }

  const tty = Boolean(process.stdin.isTTY && process.stdout.isTTY && process.env.TERM !== 'dumb' && options.ui && !options.quiet);
  const colored = Boolean(options.color && process.env.NO_COLOR === undefined && process.stdout.isTTY && process.env.TERM !== 'dumb');
  const palette = { text: '238;238;238', muted: '128;128;128', primary: '250;178;131', success: '127;216;143', warning: '245;167;66', error: '224;108;117', info: '86;182;194', border: '72;72;72' };
  const color = (tone, text) => colored ? '\x1b[38;2;' + palette[tone] + 'm' + text + '\x1b[39m' : text;
  const plain = (value) => stripVTControlCharacters(String(value)).replace(/[\x00-\x1f\x7f-\x9f]/g, '');
  const graphemes = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
  function fit(value, maximum) {
    let text = '', width = 0;
    for (const { segment } of graphemes.segment(plain(value))) {
      const size = /[\p{Extended_Pictographic}\p{Regional_Indicator}\u1100-\u115f\u2e80-\ua4cf\uac00-\ud7a3\uf900-\ufaff\uff01-\uff60]/u.test(segment) ? 2 : 1;
      if (width + size > maximum) break;
      text += segment; width += size;
    }
    return { text, width };
  }
  let localUrl = '', mode = options.logs ? 'nerd' : 'classic', dialog = false, stopping = false;
  let notice = '', requestCount = 0, failures = 0, scroll = 0, follow = true;
  let renderTimer, previous = [], entered = false;
  const history = [];
  const MIME = {
    '.html':'text/html; charset=utf-8', '.css':'text/css; charset=utf-8', '.js':'application/javascript; charset=utf-8', '.mjs':'application/javascript; charset=utf-8',
    '.json':'application/json; charset=utf-8', '.txt':'text/plain; charset=utf-8', '.xml':'application/xml', '.wasm':'application/wasm', '.png':'image/png', '.jpg':'image/jpeg', '.jpeg':'image/jpeg',
    '.gif':'image/gif', '.svg':'image/svg+xml', '.webp':'image/webp', '.avif':'image/avif', '.ico':'image/x-icon', '.woff':'font/woff', '.woff2':'font/woff2', '.ttf':'font/ttf', '.otf':'font/otf',
    '.mp4':'video/mp4', '.webm':'video/webm', '.ogg':'video/ogg', '.framercms':'application/octet-stream',
  };
  function render() {
    clearTimeout(renderTimer); renderTimer = undefined;
    if (!tty || stopping) return;
    const width = Math.max(1, (process.stdout.columns || 80) - 1), height = Math.max(1, process.stdout.rows || 24);
    const lines = Array.from({length:height}, () => ' '.repeat(width));
    const margin = width >= 30 ? 2 : 0;
    const put = (row, parts, x = margin, available = width - x - margin) => {
      if (row < 0 || row >= height) return;
      let used = 0, line = ' '.repeat(Math.max(0,x));
      for (const [tone,value] of parts) { const fitted = fit(value, Math.max(0, available - used)); line += color(tone,fitted.text); used += fitted.width; }
      lines[row] = line + ' '.repeat(Math.max(0, width - x - used));
    };
    put(0, [['muted','framer'],['text','export'],['muted','   preview v' + VERSION]]);
    if (height >= 12) {
      put(2, [[mode === 'classic' ? 'primary' : 'muted', mode === 'classic' ? '● Classic' : '  Classic'], ['muted','    '], [mode === 'nerd' ? 'primary' : 'muted', mode === 'nerd' ? '● Nerd' : '  Nerd']]);
      const top = mode === 'classic' ? Math.max(4, Math.floor(height / 3) - 1) : 4;
      put(top, [['success','● '],['text','Your site is ready']]);
      put(top + 2, [['primary',localUrl]]);
      put(top + 3, [['muted',root]]);
      if (mode === 'classic') {
        put(top + 5, [['muted',requestCount + (requestCount === 1 ? ' request' : ' requests')], [failures ? 'warning' : 'muted', failures ? ' · ' + failures + ' failed' : '']]);
        const latest = history.at(-1);
        if (latest && top + 7 < height - 3) put(top + 7, [[latest.status >= 400 ? 'warning' : 'muted', latest.method + ' ' + latest.url]]);
      } else {
        const bodyTop = 10, bodyHeight = Math.max(1, height - bodyTop - 3);
        const maximum = Math.max(0, history.length - bodyHeight);
        scroll = follow ? maximum : Math.max(0, Math.min(maximum, scroll));
        put(8, [['muted',requestCount + ' requests · ' + failures + ' failed'], ['primary',follow ? '   Following' : '   Paused · End to follow']]);
        put(9, [['border','─'.repeat(width - margin * 2)]]);
        history.slice(scroll, scroll + bodyHeight).forEach((entry, index) => {
          put(bodyTop + index, [['muted',entry.time + '  '], [entry.status >= 500 ? 'error' : entry.status >= 400 ? 'warning' : 'success',String(entry.status) + '  '], ['info',entry.method.padEnd(5) + ' '], ['text',entry.url], ['muted','  ' + entry.elapsed + 'ms']]);
        });
        if (!history.length) put(bodyTop, [['muted','Open your site to see requests here.']]);
      }
      put(height - 3, [['border','─'.repeat(width - margin * 2)]]);
      put(height - 2, [['muted',notice || (options.spa ? 'SPA fallback on' : 'SPA fallback off') + ' · ' + (cache ? 'Cache ' + cache + 's' : 'Cache off')]]);
    } else if (height >= 3) put(1, [['primary',localUrl]]);
    if (height >= 3) put(height - 1, [['primary','o'],['muted',' Open   '],['primary','n'],['muted',' Mode   '],['primary','?'],['muted',' Help   '],['primary','q'],['muted',' Stop']]);
    if (dialog) {
      const content = [ ['primary','Preview · keyboard shortcuts'], ['muted',''], ['text','o          Open the site in your browser'], ['text','n / Tab    Switch Classic / Nerd'], ['text','↑ ↓        Scroll request history'], ['text','End        Follow latest requests'], ['text','q / Ctrl+C Stop the server'], ['muted',''], ['muted','Esc / Enter Close help'] ];
      const boxWidth = Math.min(58, width), boxHeight = Math.min(content.length + 4, height);
      const x = Math.floor((width - boxWidth) / 2), y = Math.floor((height - boxHeight) / 2);
      for (let row = 0; row < boxHeight; row++) {
        if (row === 0 || row === boxHeight - 1) put(y + row, [['border','─'.repeat(boxWidth)]], x, boxWidth);
        else put(y + row, [content[row - 2] || ['muted','']], x + (boxWidth > 4 ? 2 : 0), Math.max(1,boxWidth - 4));
      }
    }
    let output = '';
    lines.forEach((line, index) => { if (line !== previous[index]) output += '\x1b[' + (index+1) + ';1H' + (colored ? '\x1b[48;2;10;10;10m' : '') + line + '\x1b[K\x1b[0m'; });
    if (output) process.stdout.write(output);
    previous = lines;
  }
  function scheduleRender() { if (tty && !stopping && !renderTimer) renderTimer = setTimeout(render, 16); }
  function record(req, res, started) {
    const entry = { method: req.method, url: plain((req.url || '/').split('?')[0]), status: res.statusCode, elapsed: Math.max(0,Math.round(performance.now()-started)), time: new Date().toLocaleTimeString('en-GB',{hour12:false}) };
    requestCount++; if (entry.status >= 400) failures++;
    history.push(entry); if (history.length > 200) { history.shift(); if (!follow) scroll = Math.max(0,scroll-1); }
    if (tty) scheduleRender();
    else if (options.logs && !options.quiet) console.log(color(entry.status >= 400 ? 'warning' : 'success',String(entry.status)) + ' ' + entry.method + ' ' + entry.url + ' ' + entry.elapsed + 'ms');
  }
  const inside = (file) => { const relative = path.relative(root, file); return relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative); };
  const server = http.createServer(async (req, res) => {
    const started = performance.now(); res.once('finish', () => record(req,res,started));
    const respond = (status, text) => { res.writeHead(status, {'Content-Type':'text/plain; charset=utf-8'}); res.end(req.method === 'HEAD' ? undefined : text); };
    if (!['GET','HEAD'].includes(req.method)) { res.setHeader('Allow','GET, HEAD'); respond(405,'Method not allowed'); return; }
    let pathname;
    try { pathname = decodeURIComponent((req.url || '/').split('?')[0]); if (!pathname.startsWith('/') || pathname.includes('\0') || pathname.includes('\\')) throw new Error(); }
    catch { respond(400,'Invalid request path'); return; }
    const requested = path.resolve(root, '.' + pathname);
    if (!inside(requested)) { respond(403,'Forbidden'); return; }
    const candidates = [requested];
    if (!path.extname(pathname)) {
      candidates.push(path.join(requested,'index.html'));
      const slug = pathname.replace(/^\/+|\/+$/g,'').replace(/[^a-zA-Z0-9_-]/g,'_').replace(/_+/g,'_');
      if (slug) candidates.push(path.join(root,'subpages',slug+'.html'));
      if (options.spa) candidates.push(path.join(root,'index.html'));
    }
    for (const candidate of candidates) {
      try {
        const real = await fs.promises.realpath(candidate);
        if (!inside(real)) { respond(403,'Forbidden'); return; }
        const stat = await fs.promises.stat(real);
        if (!stat.isFile()) continue;
        const headers = { 'Content-Type':MIME[path.extname(real).toLowerCase()] || 'application/octet-stream', 'Content-Length':stat.size, 'Access-Control-Allow-Origin':'*', 'Cache-Control':cache ? 'public, max-age=' + cache : 'no-store' };
        if (req.method === 'HEAD') { res.writeHead(200,headers); res.end(); return; }
        const stream = fs.createReadStream(real);
        stream.once('open', () => { if (!res.destroyed) { res.writeHead(200,headers); stream.pipe(res); } else stream.destroy(); });
        stream.once('error', () => { if (!res.headersSent && !res.destroyed) respond(500,'Could not read file'); else res.destroy(); });
        res.once('close', () => stream.destroy());
        return;
      } catch (error) {
        if (!['ENOENT','ENOTDIR'].includes(error.code)) { respond(error.code === 'EACCES' ? 403 : 500,'Could not read file'); return; }
      }
    }
    respond(404,'Not found');
  });
  function restore() {
    clearTimeout(renderTimer); clearTimeout(inputTimer);
    process.stdin.off('data', onInput);
    process.stdout.off('resize', onResize);
    if (entered) { entered = false; process.stdin.setRawMode(false); process.stdin.pause(); process.stdout.write('\x1b[0m\x1b[?25h\x1b[?1049l'); }
  }
  function stop() {
    if (stopping) { server.closeAllConnections(); return; }
    stopping = true; restore();
    const force = setTimeout(() => server.closeAllConnections(),1500); force.unref();
    server.close(() => { clearTimeout(force); if (!options.quiet) console.log('Preview stopped.'); });
    server.closeIdleConnections();
  }
  function openBrowser() {
    const command = process.platform === 'darwin' ? ['open',[localUrl]] : process.platform === 'win32' ? ['rundll32',['url.dll,FileProtocolHandler',localUrl]] : ['xdg-open',[localUrl]];
    const child = spawn(command[0],command[1],{stdio:'ignore',detached:true,shell:false,windowsHide:true});
    child.on('error', () => { notice = 'Could not open browser. Open ' + localUrl; if (tty) scheduleRender(); else if (!options.quiet) console.error(notice); });
    child.unref();
  }
  let inputBuffer = '', inputTimer;
  function onInput(chunk) {
    clearTimeout(inputTimer);
    inputBuffer += chunk.toString('utf8');
    consumeInput(false);
  }
  function consumeInput(flush) {
    while (inputBuffer.length) {
      const keys = ['\x1b[A','\x1b[B','\x1b[F','\x1b[4~','\x1b[6~','\x1b[5~'];
      let key = keys.find(key => inputBuffer.startsWith(key));
      if (!key && !flush && keys.some(key => key.startsWith(inputBuffer))) { inputTimer = setTimeout(() => consumeInput(true),25); return; }
      key = key || inputBuffer[0]; inputBuffer = inputBuffer.slice(key.length);
      if (key === '\x03' || key === 'q') { stop(); return; }
      if (dialog) { if (key === '\x1b' || key === '\r' || key === '?') dialog = false; scheduleRender(); continue; }
      if (key === 'o') openBrowser();
      if (key === '?' || key === 'h') dialog = true;
      if (key === 'n' || key === '\t') mode = mode === 'classic' ? 'nerd' : 'classic';
      if (mode === 'nerd') {
        if (key === '\x1b[A' || key === '\x1b[5~') { follow = false; scroll = Math.max(0,scroll-(key === '\x1b[A' ? 1 : 10)); }
        if (key === '\x1b[B' || key === '\x1b[6~') { follow = false; scroll += key === '\x1b[B' ? 1 : 10; }
        if (key === '\x1b[F' || key === '\x1b[4~') follow = true;
      }
      scheduleRender();
    }
  }
  function onResize() { previous = []; render(); }
  await new Promise((resolve,reject) => { server.once('error',reject); server.listen(port, options.host, () => { server.off('error',reject); resolve(); }); }).catch(error => {
    throw new Error(error.code === 'EADDRINUSE' ? 'Port ' + port + ' is already in use. Choose another with -p.' : 'Could not listen on ' + options.host + ':' + port + ' (' + error.code + ').');
  });
  const address = server.address();
  const localHost = ['0.0.0.0','::'].includes(options.host) ? '127.0.0.1' : options.host;
  localUrl = 'http://' + (localHost.includes(':') ? '['+localHost+']' : localHost) + ':' + address.port;
  server.on('error', error => { notice = error.message; scheduleRender(); });
  process.on('SIGINT',stop); process.on('SIGTERM',stop); process.once('exit',restore);
  if (tty) {
    entered = true; process.stdout.write('\x1b[?1049h\x1b[?25l');
    process.stdin.setRawMode(true); process.stdin.resume(); process.stdin.on('data',onInput); process.stdout.on('resize',onResize); render();
  } else if (options.quiet) console.log(localUrl);
  else {
    console.log('\n  ' + color('text','framerexport') + color('muted',' · preview v' + VERSION));
    console.log('  ' + color('success','Ready') + '  ' + localUrl);
    console.log('  ' + color('muted',plain(root)));
    console.log('  ' + color('muted',(options.spa ? 'SPA fallback on' : 'SPA fallback off') + ' · ' + (cache ? 'Cache ' + cache + 's' : 'Cache off')));
    console.log('  ' + color('muted','Press Ctrl+C to stop. Use --help for options.') + '\n');
  }
  if (options.open) openBrowser();
}
main().catch(error => fail(error.message));
