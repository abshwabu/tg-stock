#!/usr/bin/env python3
"""Report compressed APK size by native libraries, Flutter assets, DEX, and resources."""

import collections
import sys
import zipfile

apk_path = sys.argv[1]
groups = collections.Counter()
with zipfile.ZipFile(apk_path) as apk:
    for entry in apk.infolist():
        name = entry.filename
        if name.startswith("lib/"):
            group = "Native libraries"
        elif name.startswith("assets/flutter_assets/"):
            group = "Flutter assets"
        elif name.startswith("classes") and name.endswith(".dex"):
            group = "DEX bytecode"
        elif name.startswith(("res/", "resources.arsc")):
            group = "Android resources"
        else:
            group = "Other"
        groups[group] += entry.compress_size

total = sum(groups.values())
print("APK compressed component sizes:")
for label, size in sorted(groups.items(), key=lambda item: item[1], reverse=True):
    print(f"  {label}: {size / (1024 * 1024):.2f} MiB ({size:,} bytes)")
print(f"  ZIP payload total: {total / (1024 * 1024):.2f} MiB ({total:,} bytes)")
print(f"  APK file total: {__import__('os').path.getsize(apk_path) / (1024 * 1024):.2f} MiB")
