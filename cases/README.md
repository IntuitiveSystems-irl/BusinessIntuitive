# Case Study Assets

Visuals for featured projects on `painted.html`. Two case study patterns are supported:

1. **Browser-frame split** (`.featured-project`) — left text column + right column with one or two Mac-style browser-framed screenshots
2. **Mockup-only** (`.featured-project.mockup-only`) — a single fully-composed mockup image as the entire case study (use when you already have a polished Figma/Photoshop comp)

## Naming conventions

- `{slug}-marketing.{jpg|png}` — raw marketing site screenshot (split layout, primary frame)
- `{slug}-portal.{jpg|png}` — raw portal / admin screenshot (split layout, secondary frame)
- `{slug}-mockup.{jpg|png}` — fully-composed case study mockup (mockup-only layout)

## Currently referenced

| File | Used by | Pattern | Status |
|---|---|---|---|
| `veritas-marketing.jpg` | Veritas · primary frame | browser-frame | ✓ live |
| `veritas-portal.jpg` | Veritas · secondary frame | browser-frame | ✓ live |
| `hbc-mockup.jpg` | Hair by Cailey · full case visual | mockup-only | ✓ live |

## Image specs

- Format: PNG (no transparency needed — the browser-frame chrome provides the visual border)
- Width: 1600–2000px for retina sharpness
- Aspect ratio: roughly 16:9 / 1024×588 works perfectly (the frames auto-scale)
- Capture the full viewport including the site's own header — the browser chrome (traffic-light dots + URL pill) is rendered in HTML around it

## How to add another case

1. Take two screenshots: `{slug}-marketing.png` + `{slug}-portal.png`
2. Save both into this `cases/` folder
3. Copy the `.featured-project` block in `painted.html`
4. Replace: brand SVG, headline, subtitle, copy, features, CTA URL, and the two `src` attributes
5. Done.

## Graceful degradation

If a screenshot is missing, its browser frame shows a striped placeholder so the layout never breaks. Replace files in place and refresh — no code changes needed.
