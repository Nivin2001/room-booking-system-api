import pathlib
import pdfplumber

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / "tmp" / "pdfs"
FILES = [
    pathlib.Path(r"C:\Users\HP\Downloads\Cv hadeel.pdf"),
    pathlib.Path(r"C:\Users\HP\Downloads\CV Hind Baraka PDF.pdf"),
]

for src in FILES:
    parts = []
    with pdfplumber.open(src) as pdf:
        for page_index, page in enumerate(pdf.pages, 1):
            text = page.extract_text() or ""
            parts.append(f"--- PAGE {page_index} ---\n{text}")
    target = OUT / f"{src.stem}.txt"
    target.write_text("\n\n".join(parts), encoding="utf-8")
    print(target)
