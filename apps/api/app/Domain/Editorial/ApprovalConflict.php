<?php

namespace App\Domain\Editorial;

use RuntimeException;

/** A decisao nao se aplica a peca como ela esta (outra versao, outro status): 409. */
class ApprovalConflict extends RuntimeException {}
