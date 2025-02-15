<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

/**
 * Front end content element "toplink".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ContentToplink extends ContentElement
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'ce_toplink';

	/**
	 * Generate the content element
	 */
	protected function compile()
	{
		if (!$this->linkTitle)
		{
			$this->linkTitle = $GLOBALS['TL_LANG']['MSC']['backToTop'];
		}

		$this->Template->label = $this->linkTitle;
		$this->Template->title = StringUtil::specialchars($this->linkTitle);
		$this->Template->request = ampersand(Environment::get('request'));
	}
}

class_alias(ContentToplink::class, 'ContentToplink');
