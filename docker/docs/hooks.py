"""MkDocs hook: add the self-hosted Mermaid script to the site.

Material draws ```mermaid fences with Mermaid, and fetches it from unpkg.com unless a `mermaid` global already exists.
That would send every visitor's request to a third party, so the Docker image carries Mermaid (docker/docs/Dockerfile)
and this hook adds it to the build as assets/javascripts/mermaid.min.js, which mkdocs.yml lists in extra_javascript.
Done in a hook so `mkdocs serve`, `mkdocs build` and `mike deploy` all get it, and nothing vendored is committed.
"""

import os

from mkdocs.structure.files import File

MERMAID = os.environ.get("MERMAID_JS", "/opt/mermaid/mermaid.min.js")


def on_files(files, config):
    if not os.path.isfile(MERMAID):
        raise SystemExit(f"Mermaid not found at {MERMAID}. Build the docs inside the Docker image (make docs).")
    files.append(File.generated(config, "assets/javascripts/mermaid.min.js", abs_src_path=MERMAID))
    return files
