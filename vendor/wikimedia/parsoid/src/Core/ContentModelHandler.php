<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Core;

use DOMDocument;

use Wikimedia\Parsoid\Config\Env;

abstract class ContentModelHandler {

	/**
	 * @param Env $env
	 * @return DOMDocument
	 */
	abstract public function toDOM( Env $env ): DOMDocument;

	/**
	 * @param Env $env
	 * @param ?SelserData $selserData
	 * @return string
	 */
	abstract public function fromDOM(
		Env $env, ?SelserData $selserData = null
	): string;

}
