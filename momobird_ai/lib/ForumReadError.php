<?php
/**
 * The forum's own tables could not be read in a usable way (an exception, an empty
 * forum tree, no guest group, no settings, first posts that all vanished). The engine
 * aborts the run without changing anything: a bad read must never look like
 * "everything was deleted".
 */
final class MomoBird_ForumReadError extends RuntimeException
{
}
