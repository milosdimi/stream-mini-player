// server.js (clean + final)
import express from "express";

const app = express();
const PORT = process.env.PORT || 3000;

// Basic CORS for dev (your local web app)
app.use((req, res, next) => {
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Headers", "*");
  next();
});

// Health check
app.get("/", (req, res) => {
  res.type("text/plain").send("OK - IPTV proxy running");
});

// Small proxy for M3U/playlist fetch (dev/personal use)
app.get("/m3u", async (req, res) => {
  try {
    const url = String(req.query.url || "");
    if (!url) return res.status(400).type("text/plain").send("Missing ?url=");

    // Basic safety: only allow http/https
    if (!/^https?:\/\//i.test(url)) {
      return res.status(400).type("text/plain").send("Invalid url (must start with http/https)");
    }

    const upstream = await fetch(url, {
      headers: {
        "User-Agent": "Mozilla/5.0",
        "Accept": "*/*",
      },
    });

    const body = await upstream.text();

    res.status(upstream.status);
    res.setHeader(
      "Content-Type",
      upstream.headers.get("content-type") || "text/plain; charset=utf-8"
    );
    res.send(body);
  } catch (err) {
    res.status(500).type("text/plain").send(String(err));
  }
});

/*
// Optional: XMLTV proxy (EPG) - keep for later if you want
app.get("/xml", async (req, res) => {
  try {
    const url = String(req.query.url || "");
    if (!url) return res.status(400).type("text/plain").send("Missing ?url=");

    if (!/^https?:\/\//i.test(url)) {
      return res.status(400).type("text/plain").send("Invalid url (must start with http/https)");
    }

    const upstream = await fetch(url, {
      headers: {
        "User-Agent": "Mozilla/5.0",
        "Accept": "*//*",
      },
    });

    const body = await upstream.text();

    res.status(upstream.status);
    res.setHeader(
      "Content-Type",
      upstream.headers.get("content-type") || "application/xml; charset=utf-8"
    );
    res.send(body);
  } catch (err) {
    res.status(500).type("text/plain").send(String(err));
  }
});
 */

app.listen(PORT, () => {
  console.log(`Proxy running on http://localhost:${PORT}`);
});
