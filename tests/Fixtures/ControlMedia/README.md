Small encoded video fixtures from web-platform-tests, revision
`3c357d5f61457b3c51a32929b4eab128374af2b7`, `media/2x2-green.mp4`
and `media/2x2-green.webm`:
https://github.com/web-platform-tests/wpt/tree/3c357d5f61457b3c51a32929b4eab128374af2b7/media

`green.mov` retains the MP4 fixture's encoded samples and offsets and changes
only the `ftyp` major brand to `qt  ` for QuickTime MIME/container coverage.
These fixtures verify byte preservation, MIME, authorization and HTTP Range;
automated tests do not claim device codec/playback compatibility.

License: LICENSE-WPT.md (BSD 3-Clause, web-platform-tests contributors).
