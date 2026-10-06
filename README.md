# 🎮 Ultimate Tic Tac Toe

![Version](https://img.shields.io/badge/version-1.2.0-blue?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
[![GitHub Issues](https://img.shields.io/github/issues/dynamiccookies/ultimate-tic-tac-toe?style=for-the-badge)](https://github.com/dynamiccookies/ultimate-tic-tac-toe/issues)

Play Regular or Ultimate Tic Tac Toe against the computer or with another player on the same screen.

**Regular mode** uses one board. **Ultimate mode** uses nine connected boards, where each move determines the board your opponent plays next.

> [!IMPORTANT]
> This game requires PHP hosting with sessions enabled. GitHub Pages cannot run PHP. Game setup changes take effect when you click **New game**. Progress saves automatically and restores when you reopen the same game URL in the same browser.

## Key features

- Selectable **Regular** and **Ultimate** game types
- Computer opponent with **Easy**, **Normal**, and **Hard** difficulty
- Local two-player mode on the same screen
- Choice of X or O, with X always playing first
- Dark and light themes with saved preference
- Highlighted playable boards and the most recent move
- X, O, and drawn-board counters
- Undo for a full turn, including the computer reply
- Confirmed new game when a game is still in progress
- Win, loss, and draw popups
- Confetti celebrations that honor reduced-motion preferences
- Instructions in a popup that keeps the main layout compact
- Viewport-sized desktop board
- Automatic PHP session saves and immediate browser recovery

Hard difficulty uses bounded heuristic lookahead. It is not an unbeatable solver. Small screens may still require scrolling to keep the controls usable.

## How it works

1. Select **Regular Tic Tac Toe** or **Ultimate Tic Tac Toe**.
2. Choose the play mode, computer difficulty, and your mark.
3. Click **New game** to apply the selected setup.
4. Play in the highlighted board or choose an empty square in Regular mode.
5. Use **Undo turn** to reverse your last turn and the computer reply.
6. When the game ends, view the result popup or start a rematch.

### Regular Tic Tac Toe

Make three of your marks in a row, column, or diagonal to win. If the board fills without a winner, the game is a draw.

### Ultimate Tic Tac Toe

- X starts and can choose any square.
- Your square sends the next player to the matching small board. For example, the top-right square sends them to the top-right board.
- Three marks in a row win a small board. Won and drawn boards close.
- If a move sends you to a closed board, you may choose any open board.
- Win three small boards in a row, column, or diagonal to win the game.
- Drawn boards do not count toward a winning line.
- If all small boards close without a winner, the game is a draw.

## Installation

The game uses one application file:

- `index.php`

1. Download `index.php` from this repository.
2. Create a folder on your PHP website, such as `/ultimate/`.
3. Upload `index.php` into that folder.
4. Open the folder URL in your browser, such as `https://your-domain.com/ultimate/`.

No database, Composer packages, external game assets, API keys, or configuration files are required.

## Requirements

- PHP **8.0 or newer**
- PHP sessions enabled and writable session storage
- A modern browser with JavaScript enabled
- Browser local storage for theme persistence and browser progress recovery

GitHub stores the source code. Deploy the game to a PHP-capable web host to play it.

## Documentation

| Topic | Documentation |
|---|---|
| Setup and controls | [How it works](#how-it-works) |
| Regular game rules | [Regular Tic Tac Toe](#regular-tic-tac-toe) |
| Ultimate game rules | [Ultimate Tic Tac Toe](#ultimate-tic-tac-toe) |
| Installation | [Installation](#installation) |
| Hosting requirements | [Requirements](#requirements) |
| Saved progress and data | [Permissions and privacy](#permissions-and-privacy) |
| Updates and version history | [Updates](#updates) |
| Reporting problems | [Releases and support](#releases-and-support) |

## Permissions and privacy

The game does not require an account. Gameplay and computer search run in browser JavaScript.

PHP stores the move log and game settings in the browser's server session. Save requests use CSRF tokens, and PHP validates the move sequence before saving. This is a casual game, not a competitive anti-cheat service.

Progress is also saved immediately in browser local storage and restored automatically when the page opens, including after a server session expires. Clearing browser storage removes that recovery copy. Progress is specific to the browser and game URL; it does not sync across devices.

Dark and light theme preferences are stored in the browser. Two-player mode shares one screen and does not support remote multiplayer.

## Updates

Replace the hosted `index.php` with the updated file, then reload the game. Existing Ultimate session saves remain compatible with version 1.2.0.

| Version | Changes |
|---|---|
| **1.2.0** | Regular/Ultimate selector, result and instructions popups, draw-board counter, compact desktop layout, immediate browser recovery, and clearer save messages |
| **1.1.2** | Green outlines, green grids, and pale-green cells for playable boards in light mode |
| **1.1.1** | Improved light-mode grid contrast and removed the database footer comment |
| **1.1.0** | Win celebrations, computer-win message, draw banner, and highlighted winning boards |

## Releases and support

- [View the source and download files](https://github.com/dynamiccookies/ultimate-tic-tac-toe)
- [Review commit history](https://github.com/dynamiccookies/ultimate-tic-tac-toe/commits/main/)
- [Report a problem or request a feature](https://github.com/dynamiccookies/ultimate-tic-tac-toe/issues)

For bug reports, include the game type, play mode, difficulty, browser, and steps needed to reproduce the problem.

## Contributing

Issues and pull requests are welcome. Review the existing issues before submitting a duplicate request.
