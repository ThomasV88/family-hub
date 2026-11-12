---
sketch: 007
name: medical-records
question: "How is a person's medical record structured, how does the AI OCR flow (consent, run, quota wait, review) work, and how do exports work?"
winner: "D"
tags: [medical, records, ai-ocr, export, module]
---

# Sketch 007: Medical Records

## Design Question
Medical records belong to a person profile (who need not be an app user). The module holds visits by specialty, medications and allergies, vaccinations, and documents with photos. AI OCR proposes structured data from a document; a human reviews it. Records are exported by specialty, in full, or in an AI-friendly format. Which structure and flow works on phone, iPad and desktop?

## How to View
open .planning/sketches/007-medical-records/index.html (or via the local server on 127.0.0.1:8765)

Pick a person with the chips (Tomáš and Babka Mária have no account; Peter and Babka have AI switched off). On Ema, open "Správa gastroenterológ.pdf" in the documents and run AI. The toolbar button "Simulovať vyčerpaný limit AI" makes the next run end in the waiting state. "Export" opens the export panel.

## Variants
- **A: Person card with tabs** — Overview (emergency card, upcoming vaccination, last visit), Visits, Medications and allergies, Vaccinations, Documents
- **B: Timeline** — one chronological feed of visits, documents and vaccinations with specialty filter chips; emergency card and medications beside it
- **C: By specialty** — a folder (accordion) per specialty with its visits and documents; emergency card and medications beside it

- **D: C + timeline from B (winner)** — specialty folders as the default, each folder body shown as a chronological timeline (visits and documents with dots and dates); a "By specialty / Timeline" switch shows the full chronological feed with specialty filter chips (including vaccinations). Emergency card and medications beside it

## Decision (winner D)
- Person chips on top; person header shows age, "no account" marker, AI consent flag, access list, Export button
- Main area: switch "Podľa špecializácií" (default, folders) / "Časová os" (all records chronologically, filter chips per specialty and vaccinations). Emergency card plus medications and allergies in a side column on wide screens, stacked on phone
- Folder maps to the "export by specialty" scope
- AI flow as sketched: blocked without per-person consent (default off), consent notice, running, quota wait with fallback agent and time, proposal with sure/check fields, accept / reject / re-run; only accepted data is stored as verified. Manual entry stays the primary path
- Export: scope specialty or all, format for doctor (PDF) or for AI (Markdown, person name omitted), unverified AI data excluded unless chosen and then labeled
- Review of a proposal: the sketched 440 px docked panel stacks document above fields, too small for real reading. Proposed for the build: a dedicated wide Review screen on desktop and iPad (document left, fields right), document above fields on phone (not sketched, not yet confirmed by the owner)
- Plural and name forms must come from the i18n system (ICU plurals), not string concatenation (Slovak/Polish)

## What to Look For
- The person header: age, "no account" marker, AI consent flag, and who has access
- The AI flow: blocked when consent is off, consent notice when it is on, running, waiting for quota with fallback to the next agent, then a proposal to review next to the document with "check" markers on uncertain fields, accept or reject
- Whether manual entry is clearly the primary path and AI an optional help
- Export: scope (specialty or all), format (for doctor or for AI), unverified data excluded unless chosen and labeled when included
- Emergency card on every variant
- Phone: panels as bottom sheets
