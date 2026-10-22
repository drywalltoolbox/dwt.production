import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { GoogleGenAI } from '@google/genai';
import {defineConfig, loadEnv} from 'vite';

export default defineConfig(({mode}) => {
  const env = loadEnv(mode, '.', '');
  const localAiProxy = {
    name: 'optiwrite-local-ai-proxy',
    configureServer(server: any) {
      server.middlewares.use('/api/optiwrite/generate', async (req: any, res: any) => {
        res.setHeader('Content-Type', 'application/json');
        if (req.method !== 'POST') {
          res.statusCode = 405;
          res.end(JSON.stringify({ error: 'POST is required.' }));
          return;
        }
        const host = String(req.headers.host || '').split(':')[0].toLowerCase();
        const origin = String(req.headers.origin || '');
        if (!['localhost', '127.0.0.1', '::1'].includes(host) || (origin && !/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin))) {
          res.statusCode = 403;
          res.end(JSON.stringify({ error: 'Local requests only.' }));
          return;
        }
        if (!env.GEMINI_API_KEY) {
          res.statusCode = 503;
          res.end(JSON.stringify({ error: 'GEMINI_API_KEY is not configured in the local environment.' }));
          return;
        }
        try {
          let bodyText = '';
          for await (const chunk of req) {
            bodyText += chunk;
            if (bodyText.length > 512_000) throw new Error('Request exceeds the local generation size limit.');
          }
          const payload = JSON.parse(bodyText);
          const textLength = String(payload?.contents?.[0]?.parts?.[0]?.text || '').length;
          if (!payload?.config?.systemInstruction || !payload?.config?.responseSchema || !textLength || textLength > 400_000) {
            res.statusCode = 400;
            res.end(JSON.stringify({ error: 'Generation request is incomplete or exceeds the input limit.' }));
            return;
          }
          const client = new GoogleGenAI({ apiKey: env.GEMINI_API_KEY });
          const result = await client.models.generateContent({
            model: 'gemini-3.1-flash-lite',
            contents: payload.contents,
            config: { ...payload.config, httpOptions: { timeout: 120_000 } }
          });
          res.statusCode = 200;
          res.end(JSON.stringify({ text: result.text || '', candidates: result.candidates || [] }));
        } catch (error: any) {
          res.statusCode = 502;
          res.end(JSON.stringify({ error: String(error?.message || 'Gemini generation failed.').slice(0, 500) }));
        }
      });
    }
  };
  return {
    plugins: [localAiProxy, react(), tailwindcss()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      },
    },
    server: {
      // HMR is disabled in AI Studio via DISABLE_HMR env var.
      // Do not modifyâfile watching is disabled to prevent flickering during agent edits.
      hmr: process.env.DISABLE_HMR !== 'true',
      host: '127.0.0.1',
    },
  };
});
