<?php
declare(strict_types=1);

// index.php?p=file.item_image&id=N : the picture of an item, for the FB POS and the item form (logged-in users only)
FbPos::send((int)g('id'));
