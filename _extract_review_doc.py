from docx import Document
from pathlib import Path
import zipfile
p=Path(r'C:\Users\User\Downloads\FurniCraft_Chapters_4_and_5_Draft.docx')
d=Document(p)
lines=[]
for i, para in enumerate(d.paragraphs):
    t=para.text.strip().replace('\n',' / ')
    if t: lines.append(f'P{i:03} [{para.style.name}] {t}')
for ti, table in enumerate(d.tables):
    lines.append(f'--- TABLE {ti} ({len(table.rows)} rows x {len(table.columns)} cols) ---')
    for ri,row in enumerate(table.rows):
        lines.append(' | '.join(c.text.replace('\n',' / ') for c in row.cells))
Path(r'C:\ashproject\furnicraft-system\_review_ch4_5_extract.txt').write_text('\n'.join(lines),encoding='utf-8')
with zipfile.ZipFile(p) as z:
    media=[n for n in z.namelist() if n.startswith('word/media/')]
    print('PARAGRAPHS',len(d.paragraphs),'TABLES',len(d.tables),'IMAGES',len(media),'INLINE_SHAPES',len(d.inline_shapes),'SECTIONS',len(d.sections))
    print('COMMENTS_PART', 'word/comments.xml' in z.namelist())
    print('TRACKED_CHANGES', b'<w:ins' in z.read('word/document.xml') or b'<w:del' in z.read('word/document.xml'))
