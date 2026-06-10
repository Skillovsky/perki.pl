#!/usr/bin/env python3
"""
przygotuj_sample.py - faza 2 (silnik sample'owy Perki Drum Hero)

Konwertuje nagrania WAV zestawu Jacka na OGG i generuje samples/manifest.json,
ktory wczyta silnik sample'owy gry.

Uzycie:
  python3 przygotuj_sample.py <folder_z_wav> [folder_wyjsciowy=./samples]

Wymagania: ffmpeg w PATH (macOS: brew install ffmpeg).

Nazewnictwo wejsciowe (WAV, przyciete do transjentu w Logic Pro):
  <bęben>_v<warstwa>_rr<numer>.wav
  np. kick_v1_rr1.wav, snare_v3_rr2.wav, hihat_open_v2_rr1.wav
Dozwolone bębny: kick, snare, hihat, hihat_open, crash, ride, tom1, tom2, ftom.

WAZNE: nie normalizuj per plik. Relatywna glosnosc miedzy warstwami velocity
to informacja muzyczna. Skrypt tylko konwertuje (OGG Vorbis q5, 44.1 kHz).
"""
import json, re, subprocess, sys
from pathlib import Path

DRUMS = {"kick","snare","hihat","hihat_open","crash","ride","tom1","tom2","ftom"}
PAT = re.compile(r"^(" + "|".join(DRUMS) + r")_v\d+_rr\d+$")

def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    src = Path(sys.argv[1])
    dst = Path(sys.argv[2]) if len(sys.argv) > 2 else Path("samples")
    dst.mkdir(parents=True, exist_ok=True)

    manifest, skipped = {}, []
    for wav in sorted(src.glob("*.wav")):
        stem = wav.stem.lower()
        if not PAT.match(stem):
            skipped.append(wav.name); continue
        drum = stem.split("_v")[0]
        ogg = dst / f"{stem}.ogg"
        subprocess.run(
            ["ffmpeg","-y","-loglevel","error","-i",str(wav),
             "-ar","44100","-c:a","libvorbis","-q:a","5",str(ogg)],
            check=True)
        manifest.setdefault(drum, []).append(ogg.name)

    (dst / "manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")

    total_mb = sum(f.stat().st_size for f in dst.glob("*.ogg")) / 1e6
    print(f"OK: {sum(len(v) for v in manifest.values())} sampli, "
          f"{len(manifest)} bebnow, {total_mb:.1f} MB -> {dst}/manifest.json")
    if total_mb > 10:
        print("UWAGA: paczka > 10 MB, rozwaz q:a 4 albo mniej round robinow.")
    if skipped:
        print("Pominiete (zla nazwa):", ", ".join(skipped))

if __name__ == "__main__":
    main()
