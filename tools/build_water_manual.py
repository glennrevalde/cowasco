#!/usr/bin/env python3
"""
Builds the downloadable water-billing user manual (Help Center > Download) from the same content the in-app manual
shows: water/resources/manuals/user_manual_content.json.

    python3 tools/build_water_manual.py            # writes Water_Billing_User_Manual.docx
    python3 tools/build_water_manual.py --pdf      # also converts it to PDF with LibreOffice (soffice)

Needs python-docx (pip install python-docx). Run it again after editing the JSON so the downloads match the pages.
"""
import json
import os
import shutil
import subprocess
import sys

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Pt, RGBColor, Cm

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIR = os.path.join(ROOT, 'water', 'resources', 'manuals')
SRC = os.path.join(DIR, 'user_manual_content.json')
OUT = os.path.join(DIR, 'Water_Billing_User_Manual.docx')
INK = RGBColor(0x1F, 0x29, 0x33)
BLUE = RGBColor(0x0B, 0x5E, 0xD7)


def styled(doc):
    st = doc.styles['Normal']
    st.font.name = 'Segoe UI'
    st.font.size = Pt(10)
    st.font.color.rgb = INK
    for name, size in (('Title', 24), ('Heading 1', 16), ('Heading 2', 12.5), ('Heading 3', 11)):
        s = doc.styles[name]
        s.font.name = 'Segoe UI'
        s.font.size = Pt(size)
        s.font.color.rgb = BLUE if name != 'Title' else INK
        s.font.bold = True
    for sec in doc.sections:
        sec.left_margin = sec.right_margin = Cm(2.2)
        sec.top_margin = sec.bottom_margin = Cm(2)


def bullets(doc, items, numbered=False):
    """A bulleted list, or a numbered one that starts at 1 (Word's shared list numbering would run on)."""
    for n, it in enumerate(items, 1):
        if not numbered:
            doc.add_paragraph(str(it), style='List Bullet')
            continue
        p = doc.add_paragraph('%d.\t%s' % (n, it))
        p.paragraph_format.left_indent = Cm(0.9)
        p.paragraph_format.first_line_indent = Cm(-0.6)
        p.paragraph_format.tab_stops.add_tab_stop(Cm(0.9))
        p.paragraph_format.space_after = Pt(2)


def main():
    with open(SRC, encoding='utf-8') as fh:
        d = json.load(fh)
    m = d.get('metadata', {})
    doc = Document()
    styled(doc)

    doc.add_paragraph(m.get('title', 'User Manual'), style='Title')
    p = doc.add_paragraph(m.get('subtitle', ''))
    p.runs[0].font.size = Pt(12) if p.runs else None
    meta = doc.add_paragraph()
    meta.add_run('Version %s, revised %s' % (m.get('version', ''), m.get('revision_date', ''))).italic = True
    if m.get('audience'):
        doc.add_paragraph('For: ' + m['audience'])
    if m.get('support_note'):
        doc.add_paragraph(m['support_note'])

    doc.add_heading('Contents', level=1)
    for c in d.get('chapters', []):
        doc.add_paragraph('%s. %s' % (c.get('number', ''), c.get('title', '')))
    doc.add_page_break()

    if d.get('quick_start'):
        doc.add_heading('Quick start', level=1)
        for q in d['quick_start']:
            doc.add_heading(q.get('title', ''), level=3)
            doc.add_paragraph(q.get('text', ''))
    if d.get('role_guides'):
        doc.add_heading('Roles and responsibilities', level=1)
        for r in d['role_guides']:
            doc.add_heading(r.get('name', ''), level=3)
            bullets(doc, r.get('responsibilities', []))

    for c in d.get('chapters', []):
        doc.add_page_break()
        doc.add_heading('%s. %s' % (c.get('number', ''), c.get('title', '')), level=1)
        for s in c.get('sections', []):
            if s.get('heading'):
                doc.add_heading(s['heading'], level=2)
            for para in s.get('paragraphs', []):
                doc.add_paragraph(para)
            if s.get('steps'):
                bullets(doc, s['steps'], numbered=True)
            if s.get('bullets'):
                bullets(doc, s['bullets'])

    if d.get('articles'):
        doc.add_page_break()
        doc.add_heading('Procedures', level=1)
        for a in d['articles']:
            doc.add_heading(a.get('title', ''), level=2)
            if a.get('summary'):
                doc.add_paragraph(a['summary'])
            bullets(doc, a.get('steps', []), numbered=True)
            for n in a.get('notes', []):
                q = doc.add_paragraph()
                q.add_run('Note: ').bold = True
                q.add_run(n)

    if d.get('troubleshooting'):
        doc.add_page_break()
        doc.add_heading('Troubleshooting', level=1)
        t = doc.add_table(rows=1, cols=3)
        t.style = 'Light Grid Accent 1'
        for i, h in enumerate(('Problem', 'Check', 'Avoid')):
            t.rows[0].cells[i].text = h
        for r in d['troubleshooting']:
            cells = t.add_row().cells
            cells[0].text, cells[1].text, cells[2].text = r.get('problem', ''), r.get('checks', ''), r.get('avoid', '')
    if d.get('faqs'):
        doc.add_heading('Frequently asked questions', level=1)
        for f in d['faqs']:
            q = doc.add_paragraph()
            q.add_run(f.get('question', '')).bold = True
            doc.add_paragraph(f.get('answer', ''))
    if d.get('glossary'):
        doc.add_heading('Glossary', level=1)
        for g in d['glossary']:
            q = doc.add_paragraph()
            q.add_run(g.get('term', '') + ': ').bold = True
            q.add_run(g.get('definition', ''))

    for sec in doc.sections:
        f = sec.footer.paragraphs[0]
        f.text = m.get('title', '')
        f.alignment = WD_ALIGN_PARAGRAPH.CENTER
    doc.save(OUT)
    print('wrote', OUT)

    if '--pdf' in sys.argv:
        soffice = shutil.which('soffice') or shutil.which('libreoffice')
        if not soffice:
            sys.exit('soffice (LibreOffice) is not installed: the PDF was not made.')
        subprocess.run([soffice, '--headless', '--convert-to', 'pdf', '--outdir', DIR, OUT], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        print('wrote', OUT[:-5] + '.pdf')


if __name__ == '__main__':
    main()
