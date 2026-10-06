# Ultimate Tic Tac Toe — v1.2.0

Upload index.php into a folder on your PHP website and open that folder in your browser. Requires PHP 8.0+ with sessions enabled and a modern browser. No database, Composer, external assets, or configuration required.

Example: upload to /ultimate/ and visit https://your-domain.com/ultimate/.

Features: Regular and Ultimate Tic Tac Toe, result and instructions popups, draw-board count, viewport-sized desktop board, computer opponent (Easy, Normal, Hard), local two-player mode, choice of X/O, dark/light theme, legal-board highlighting, board ownership and draw indicators, undo a full turn, confirmed new game, automatic session saves and refresh recovery. Hard uses bounded heuristic lookahead; it is not an unbeatable solver.

Rules: a played square directs the opponent to its matching board. Won/full boards close; a closed target gives free choice among open boards. Three owned boards in a row wins. Drawn boards do not count toward a winning line. A fully closed big board without a winning line is a draw.

Gameplay and computer search run in browser JavaScript. PHP isolates saves per browser session, checks CSRF tokens and validates the move log before saving. This is a casual game, not a competitive anti-cheat service. Two-player mode shares a screen; it does not support remote multiplayer. Browser sessions are independent; this game does not use accounts. Progress is also saved immediately in browser local storage and restored automatically when the page opens, including after a server session expires. Clearing browser storage removes this recovery copy. Saves are specific to this browser and game URL.

Changing settings takes effect when New game is clicked. Undo reverses your last turn and the computer reply. X always begins. Dark/light preference is saved in browser storage.

Version 1.1.0 adds confetti for human/local-player wins, a computer-win loss message, a draw banner, and highlighted winning boards. Celebrations honor reduced-motion preferences. Replace index.php to upgrade; existing session saves remain compatible.

Version 1.1.1 removes the database footer comment and improves light-mode grid contrast. Gameplay and AI are unchanged.

Version 1.1.2 highlights playable boards in light mode with a green outline, green grid, and pale-green cells. Dark mode is unchanged.

Version 1.2.0 adds a Regular/Ultimate game selector, win/loss/draw and How to Play dialogs, a draw-board counter, a compact desktop layout, immediate browser recovery, and clearer automatic-save messages. Small screens may still require scrolling to keep controls usable.
