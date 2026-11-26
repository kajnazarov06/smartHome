#!/usr/bin/env node
// Fake ESP32 clock station: implements the device local API contract (HTTP + WebSocket)
// so the backend and UI can be developed without hardware. Zero dependencies.
//
//   node tools/fake-clock/fake-clock.mjs [--port 8090] [--id clock-f4k3]
//
// The pairing code is printed to the console (the real clock shows it on its display).
// No mDNS: add it in the app by IP, or set DISCOVERY_PROBE_SUBNET. When not on port 80,
// set DEVICE_PORT in backend/.env to the same port.

import http from 'node:http';
import crypto from 'node:crypto';

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};

const PORT = Number(arg('port', process.env.PORT ?? 8090));
const ID = arg('id', process.env.CLOCK_ID ?? 'clock-f4k3');
const API_VERSION = 1;
const MAX_PAIR_FAILURES = 5; // then pairing is locked for LOCKOUT_MS
const LOCKOUT_MS = 5 * 60_000;

const state = {
  name: `ClockStation-${ID.slice(-4).toUpperCase()}`,
  tz: 'Asia/Ho_Chi_Minh',
  brightness: 70,
  snooze_min: 9,
  tokenHash: null,
  pairing: null, // {code, expiresAt}
  failures: 0,
  lockedUntil: 0,
  alarms: [],
  nextAlarmId: 1,
  temperature: 24 + Math.random() * 2,
  humidity: 55 + Math.random() * 5,
  bootedAt: Date.now(),
};
const sockets = new Set();

const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ api_version: API_VERSION, ...body }));
}

function lockedReply(res) {
  return send(res, 429, { error: 'locked', retry_after: Math.ceil((state.lockedUntil - Date.now()) / 1000) });
}

function readJson(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (c) => (data += c));
    req.on('end', () => {
      try { resolve(data ? JSON.parse(data) : {}); } catch { resolve(null); }
    });
  });
}

function authorized(token) {
  return state.tokenHash !== null && token && sha256(token) === state.tokenHash;
}

function nextAlarm() {
  const enabled = state.alarms.filter((a) => a.enabled);
  return enabled.length ? enabled.map((a) => a.time).sort()[0] : null;
}

function snapshot() {
  return {
    time: new Date().toISOString(),
    tz: state.tz,
    temperature_c: Math.round(state.temperature * 10) / 10,
    humidity_pct: Math.round(state.humidity),
    wifi_rssi: -50 - Math.round(Math.random() * 10),
    uptime_s: Math.round((Date.now() - state.bootedAt) / 1000),
    next_alarm: nextAlarm(),
  };
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const path = url.pathname;
  const bearer = (req.headers.authorization ?? '').replace(/^Bearer\s+/i, '');
  log(req.method, path);

  if (req.method === 'GET' && path === '/api/info') {
    return send(res, 200, {
      id: ID, type: 'clock', name: state.name, fw: '0.0.0-fake', mac: 'FA:KE:00:00:' + ID.slice(-4, -2) + ':' + ID.slice(-2),
      paired: state.tokenHash !== null, capabilities: ['temperature', 'humidity', 'alarm', 'clock'],
    });
  }

  if (req.method === 'POST' && path === '/api/pair/start') {
    if (Date.now() < state.lockedUntil) return lockedReply(res);
    const code = String(crypto.randomInt(0, 1_000_000)).padStart(6, '0');
    state.pairing = { code, expiresAt: Date.now() + 120_000 };
    log(`\n\n    PAIRING CODE: ${code}   (valid 120 s)\n`);
    return send(res, 200, { expires_in: 120 });
  }

  if (req.method === 'POST' && path === '/api/pair/confirm') {
    if (Date.now() < state.lockedUntil) return lockedReply(res);
    const body = await readJson(req);
    const ok = state.pairing && Date.now() < state.pairing.expiresAt && body?.code === state.pairing.code;
    if (!ok) {
      state.failures++;
      if (state.failures >= MAX_PAIR_FAILURES) {
        state.failures = 0;
        state.lockedUntil = Date.now() + LOCKOUT_MS;
        return lockedReply(res);
      }
      return send(res, 401, { error: 'invalid code', attempts_left: MAX_PAIR_FAILURES - state.failures });
    }
    const token = crypto.randomBytes(32).toString('hex');
    state.tokenHash = sha256(token);
    state.pairing = null;
    state.failures = 0;
    log('paired');
    return send(res, 200, { token });
  }

  if (!authorized(bearer)) return send(res, 401, { error: 'unauthorized' });

  if (req.method === 'POST' && path === '/api/unpair') {
    state.tokenHash = null;
    for (const s of sockets) s.destroy();
    log('unpaired');
    return send(res, 200, {});
  }
  if (req.method === 'GET' && path === '/api/state') return send(res, 200, snapshot());
  if (req.method === 'GET' && path === '/api/alarms') return send(res, 200, { alarms: state.alarms });
  if (req.method === 'POST' && path === '/api/alarms') {
    if (state.alarms.length >= 10) return send(res, 400, { error: 'alarm limit reached' });
    const body = await readJson(req);
    const alarm = { id: state.nextAlarmId++, time: '07:00', days: [], enabled: true, label: null, ...body };
    state.alarms.push(alarm);
    return send(res, 201, alarm);
  }
  const m = path.match(/^\/api\/alarms\/(\d+)$/);
  if (m) {
    const alarm = state.alarms.find((a) => a.id === Number(m[1]));
    if (!alarm) return send(res, 404, { error: 'no such alarm' });
    if (req.method === 'PUT') return send(res, 200, Object.assign(alarm, await readJson(req), { id: alarm.id }));
    if (req.method === 'DELETE') {
      state.alarms = state.alarms.filter((a) => a !== alarm);
      return send(res, 200, {});
    }
  }
  if (req.method === 'PUT' && path === '/api/settings') {
    const body = await readJson(req);
    for (const k of ['name', 'tz', 'brightness', 'snooze_min']) if (body?.[k] !== undefined) state[k] = body[k];
    return send(res, 200, { name: state.name, tz: state.tz, brightness: state.brightness, snooze_min: state.snooze_min });
  }

  send(res, 404, { error: 'not found' });
});

// ---- Minimal RFC 6455 server (text frames out, ping/pong/close in) ----------------------------

function frame(opcode, payload = Buffer.alloc(0)) {
  const len = payload.length;
  const header = len < 126 ? Buffer.from([0x80 | opcode, len])
    : len < 65536 ? Buffer.from([0x80 | opcode, 126, len >> 8, len & 255])
    : (() => { const b = Buffer.alloc(10); b[0] = 0x80 | opcode; b[1] = 127; b.writeBigUInt64BE(BigInt(len), 2); return b; })();
  return Buffer.concat([header, payload]);
}

// A text frame with the contract's envelope (`api_version` on every message).
const wsMessage = (obj) => frame(1, Buffer.from(JSON.stringify({ api_version: API_VERSION, ...obj })));
const broadcast = (obj) => { const msg = wsMessage(obj); for (const sock of sockets) sock.write(msg); };

server.on('upgrade', (req, socket) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname !== '/ws' || !authorized(url.searchParams.get('token'))) {
    socket.end('HTTP/1.1 401 Unauthorized\r\n\r\n');
    return;
  }
  const accept = crypto.createHash('sha1').update(req.headers['sec-websocket-key'] + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
  socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);
  sockets.add(socket);
  log('ws connected');
  socket.write(wsMessage({ type: 'state', ...snapshot() }));

  let buf = Buffer.alloc(0);
  socket.on('data', (chunk) => {
    buf = Buffer.concat([buf, chunk]);
    while (buf.length >= 2) {
      const opcode = buf[0] & 0x0f;
      let len = buf[1] & 0x7f, off = 2;
      if (len === 126) { len = buf.readUInt16BE(2); off = 4; } else if (len === 127) { len = Number(buf.readBigUInt64BE(2)); off = 10; }
      const masked = buf[1] & 0x80;
      const total = off + (masked ? 4 : 0) + len;
      if (buf.length < total) break;
      let payload = buf.subarray(off + (masked ? 4 : 0), total);
      if (masked) { const mask = buf.subarray(off, off + 4); payload = Buffer.from(payload.map((b, i) => b ^ mask[i % 4])); }
      buf = buf.subarray(total);
      if (opcode === 0x9) socket.write(frame(0xA, payload)); // ping -> pong
      if (opcode === 0x8) { socket.end(frame(0x8, payload)); }
    }
  });
  const drop = () => { sockets.delete(socket); log('ws closed'); };
  socket.on('close', drop);
  socket.on('error', drop);
});

// Sensor simulation: read every 2 s (the real clock: 10 s), push on change of ±0.2 °C / ±1 % or every 60 s.
let last = { t: -99, h: -99, at: 0 };
setInterval(() => {
  state.temperature += (Math.random() - 0.5) * 0.3;
  state.humidity += (Math.random() - 0.5) * 1.2;
  const s = snapshot();
  if (Math.abs(s.temperature_c - last.t) >= 0.2 || Math.abs(s.humidity_pct - last.h) >= 1 || Date.now() - last.at >= 60_000) {
    last = { t: s.temperature_c, h: s.humidity_pct, at: Date.now() };
    broadcast({ type: 'state', ...s });
  }
}, 2000);

// Ring alarms (minute resolution) so `alarm_ringing` can be tested.
setInterval(() => {
  const now = new Date();
  if (now.getSeconds() !== 0) return;
  const hhmm = now.toTimeString().slice(0, 5);
  const isoDay = ((now.getDay() + 6) % 7) + 1;
  for (const a of state.alarms) {
    if (a.enabled && a.time === hhmm && (a.days.length === 0 || a.days.includes(isoDay))) {
      log(`ALARM ${a.id} ringing (${a.label ?? ''})`);
      broadcast({ type: 'alarm_ringing', id: a.id });
    }
  }
}, 1000);

server.listen(PORT, '0.0.0.0', () => log(`fake clock ${ID} listening on :${PORT}`));
