<?php
declare(strict_types=1);

namespace Fyre\ORM\Exceptions;

use Fyre\ORM\Entity;
use Throwable;

/**
 * Represents an error when an entity cannot be saved or deleted.
 */
class PersistenceFailedException extends OrmException
{
    /**
     * Constructs a PersistenceFailedException.
     *
     * @param string $message The message.
     * @param Entity $entity The Entity that could not be saved or deleted.
     * @param int $code The error code.
     * @param Throwable|null $previous The previous exception.
     */
    public function __construct(
        string $message,
        protected Entity $entity,
        int $code = 0,
        Throwable|null $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns the Entity that could not be saved or deleted.
     *
     * @return Entity The Entity.
     */
    public function getEntity(): Entity
    {
        return $this->entity;
    }
}
