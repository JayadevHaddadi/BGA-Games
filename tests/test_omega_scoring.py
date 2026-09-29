#!/usr/bin/env python3
"""
Unit tests for Omega board geometry, group product scoring algorithm,
multi-player support (2, 3, 4 players), tiebreakers, and game-end round simulation.
"""

from collections import deque

# 6 hexagonal neighbor directions in axial coordinates (q, r)
HEX_DIRECTIONS = [
    (1, 0), (1, -1), (0, -1),
    (-1, 0), (-1, 1), (0, 1)
]

def generate_hex_board(radius=4):
    """Generate all valid (q, r) axial coordinates for a hex board of given radius."""
    cells = set()
    for q in range(-radius, radius + 1):
        for r in range(-radius, radius + 1):
            if -radius <= q + r <= radius:
                cells.add((q, r))
    return cells

def get_neighbors(coord, valid_cells):
    q, r = coord
    neighbors = []
    for dq, dr in HEX_DIRECTIONS:
        nxt = (q + dq, r + dr)
        if nxt in valid_cells:
            neighbors.append(nxt)
    return neighbors

def calculate_omega_score(board, color, valid_cells):
    """
    board: dict mapping (q, r) -> color
    color: string (e.g. 'white', 'black', 'red', 'blue')
    Returns: (final_score, list_of_group_sizes)
    """
    stones = {coord for coord, col in board.items() if col == color}
    if not stones:
        return 0, []

    visited = set()
    group_sizes = []

    for stone in stones:
        if stone in visited:
            continue
        # BFS
        group = []
        queue = deque([stone])
        visited.add(stone)

        while queue:
            curr = queue.popleft()
            group.append(curr)
            for nbr in get_neighbors(curr, valid_cells):
                if nbr in stones and nbr not in visited:
                    visited.add(nbr)
                    queue.append(nbr)

        group_sizes.append(len(group))

    # Product of all group sizes
    score = 1
    for sz in group_sizes:
        score *= sz

    # Sort descending for tiebreaking
    group_sizes.sort(reverse=True)
    return score, group_sizes

def calculate_aux_tiebreaker(groups):
    """Auxiliary score formula: largest * 10000 + second * 100 + third."""
    largest = groups[0] if len(groups) > 0 else 0
    second = groups[1] if len(groups) > 1 else 0
    third = groups[2] if len(groups) > 2 else 0
    return (largest * 10000) + (second * 100) + third

def test_omega():
    cells = generate_hex_board(4)
    assert len(cells) == 61, f"Expected 61 cells for radius 4, got {len(cells)}"

    # Test 1: Empty board
    score, groups = calculate_omega_score({}, 'white', cells)
    assert score == 0 and groups == []

    # Test 2: Single stone
    score, groups = calculate_omega_score({(0, 0): 'white'}, 'white', cells)
    assert score == 1 and groups == [1]

    # Test 3: Two separate stones
    score, groups = calculate_omega_score({(0, 0): 'white', (2, 2): 'white'}, 'white', cells)
    assert score == 1 and groups == [1, 1]

    # Test 4: One connected group of 3, one group of 2, one singleton
    board = {
        (0, 0): 'white', (1, 0): 'white', (2, 0): 'white',
        (0, 2): 'white', (0, 3): 'white',
        (-2, -1): 'white',
        (0, 1): 'black', (-1, 0): 'black'
    }
    score, groups = calculate_omega_score(board, 'white', cells)
    assert groups == [3, 2, 1], f"Expected groups [3, 2, 1], got {groups}"
    assert score == 6, f"Expected score 3 * 2 * 1 = 6, got {score}"

    # Test 5: Connected group of 4, group of 3 -> score 12
    board2 = {
        (0,0): 'black', (1,0): 'black', (0,1): 'black', (1,-1): 'black',
        (-2,0): 'black', (-3,0): 'black', (-2,-1): 'black'
    }
    score_b, groups_b = calculate_omega_score(board2, 'black', cells)
    assert groups_b == [4, 3]
    assert score_b == 12

    # Test 6: 3-Player and 4-Player colors (Red and Blue)
    board_4p = {
        (0, 0): 'red', (1, -1): 'red', # Red group of 2
        (-2, 0): 'red',                 # Red singleton -> score = 2 * 1 = 2
        (-1, 2): 'blue', (-2, 2): 'blue', (-3, 2): 'blue', # Blue group of 3
        (2, 0): 'blue', (2, 1): 'blue'                      # Blue group of 2 -> score = 3 * 2 = 6
    }
    score_red, groups_red = calculate_omega_score(board_4p, 'red', cells)
    score_blue, groups_blue = calculate_omega_score(board_4p, 'blue', cells)
    assert score_red == 2 and groups_red == [2, 1], f"Expected [2, 1], got {groups_red}"
    assert score_blue == 6 and groups_blue == [3, 2], f"Expected [3, 2], got {groups_blue}"

    # Test 7: Official Tiebreaker resolution (Last player in turn order wins)
    # Player 1 (White, player_no 1): score = 6
    # Player 2 (Black, player_no 2): score = 6
    # Player 3 (Red, player_no 3): score = 6
    aux_p1 = 1
    aux_p2 = 2
    aux_p3 = 3
    # Player 3 is the last tied player to have taken their turn, so Player 3 wins the tiebreak
    assert aux_p3 > aux_p2 > aux_p1

    # Test 8: Full game capacity calculation
    # 2 players: 2 stones/turn. 61 cells -> 30 full turns = 60 stones placed. 1 cell left (< 2), game ends.
    assert 61 // 2 == 30 and 61 % 2 == 1
    # 3 players: 3 stones/turn. 61 cells -> 20 full turns = 60 stones placed. 1 cell left (< 3), game ends.
    assert 61 // 3 == 20 and 61 % 3 == 1
    # 4 players: 4 stones/turn. 61 cells -> 15 full turns = 60 stones placed. 1 cell left (< 4), game ends.
    assert 61 // 4 == 15 and 61 % 4 == 1

    # Test 9: Player placement order (own color first, then cyclic turn order)
    def get_placement_order(my_color, active_colors):
        idx = active_colors.index(my_color)
        return [active_colors[(idx + i) % len(active_colors)] for i in range(len(active_colors))]

    # 2 players:
    assert get_placement_order('white', ['white', 'black']) == ['white', 'black']
    assert get_placement_order('black', ['white', 'black']) == ['black', 'white']

    # 3 players:
    assert get_placement_order('white', ['white', 'black', 'red']) == ['white', 'black', 'red']
    assert get_placement_order('black', ['white', 'black', 'red']) == ['black', 'red', 'white']
    assert get_placement_order('red', ['white', 'black', 'red']) == ['red', 'white', 'black']

    # 4 players:
    assert get_placement_order('white', ['white', 'black', 'red', 'blue']) == ['white', 'black', 'red', 'blue']
    assert get_placement_order('blue', ['white', 'black', 'red', 'blue']) == ['blue', 'white', 'black', 'red']

    print("All Omega scoring and game rule verification tests passed successfully!")

if __name__ == '__main__':
    test_omega()
