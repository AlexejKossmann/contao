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
 * Provide methods regarding calendars.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Calendar extends Frontend
{
	/**
	 * Current events
	 * @var array
	 */
	protected $arrEvents = array();

	/**
	 * Update a particular RSS feed
	 *
	 * @param integer $intId
	 */
	public function generateFeed($intId)
	{
		$objCalendar = CalendarFeedModel::findByPk($intId);

		if ($objCalendar === null)
		{
			return;
		}

		$objCalendar->feedName = $objCalendar->alias ?: 'calendar' . $objCalendar->id;

		// Delete XML file
		if (Input::get('act') == 'delete')
		{
			$webDir = StringUtil::stripRootDir(System::getContainer()->getParameter('contao.web_dir'));

			$this->import(Files::class, 'Files');
			$this->Files->delete($webDir . '/share/' . $objCalendar->feedName . '.xml');
		}

		// Update XML file
		else
		{
			$this->generateFiles($objCalendar->row());
			$this->log('Generated calendar feed "' . $objCalendar->feedName . '.xml"', __METHOD__, TL_CRON);
		}
	}

	/**
	 * Delete old files and generate all feeds
	 */
	public function generateFeeds()
	{
		$this->import(Automator::class, 'Automator');
		$this->Automator->purgeXmlFiles();

		$objCalendar = CalendarFeedModel::findAll();

		if ($objCalendar !== null)
		{
			while ($objCalendar->next())
			{
				$objCalendar->feedName = $objCalendar->alias ?: 'calendar' . $objCalendar->id;
				$this->generateFiles($objCalendar->row());
				$this->log('Generated calendar feed "' . $objCalendar->feedName . '.xml"', __METHOD__, TL_CRON);
			}
		}
	}

	/**
	 * Generate all feeds including a certain calendar
	 *
	 * @param integer $intId
	 */
	public function generateFeedsByCalendar($intId)
	{
		$objFeed = CalendarFeedModel::findByCalendar($intId);

		if ($objFeed !== null)
		{
			while ($objFeed->next())
			{
				$objFeed->feedName = $objFeed->alias ?: 'calendar' . $objFeed->id;

				// Update the XML file
				$this->generateFiles($objFeed->row());
				$this->log('Generated calendar feed "' . $objFeed->feedName . '.xml"', __METHOD__, TL_CRON);
			}
		}
	}

	/**
	 * Generate an XML file and save it to the root directory
	 *
	 * @param array $arrFeed
	 */
	protected function generateFiles($arrFeed)
	{
		$arrCalendars = StringUtil::deserialize($arrFeed['calendars']);

		if (empty($arrCalendars) || !\is_array($arrCalendars))
		{
			return;
		}

		$strType = ($arrFeed['format'] == 'atom') ? 'generateAtom' : 'generateRss';
		$strLink = $arrFeed['feedBase'] ?: Environment::get('base');
		$strFile = $arrFeed['feedName'];

		$objFeed = new Feed($strFile);
		$objFeed->link = $strLink;
		$objFeed->title = $arrFeed['title'];
		$objFeed->description = $arrFeed['description'];
		$objFeed->language = $arrFeed['language'];
		$objFeed->published = $arrFeed['tstamp'];

		$arrUrls = array();
		$this->arrEvents = array();
		$time = time();

		// Get the upcoming events
		$objArticle = CalendarEventsModel::findUpcomingByPids($arrCalendars, $arrFeed['maxItems']);

		// Parse the items
		if ($objArticle !== null)
		{
			while ($objArticle->next())
			{
				$jumpTo = $objArticle->getRelated('pid')->jumpTo;

				// No jumpTo page set (see #4784)
				if (!$jumpTo)
				{
					continue;
				}

				// Get the jumpTo URL
				if (!isset($arrUrls[$jumpTo]))
				{
					$objParent = PageModel::findWithDetails($jumpTo);

					// A jumpTo page is set but does no longer exist (see #5781)
					if ($objParent === null)
					{
						$arrUrls[$jumpTo] = false;
					}
					else
					{
						$arrUrls[$jumpTo] = $objParent->getAbsoluteUrl(Config::get('useAutoItem') ? '/%s' : '/events/%s');
					}
				}

				// Skip the event if it requires a jumpTo URL but there is none
				if ($objArticle->source == 'default' && $arrUrls[$jumpTo] === false)
				{
					continue;
				}

				$strUrl = $arrUrls[$jumpTo];
				$this->addEvent($objArticle, $objArticle->startTime, $objArticle->endTime, $strUrl);

				// Recurring events
				if ($objArticle->recurring)
				{
					$arrRepeat = StringUtil::deserialize($objArticle->repeatEach);

					if (!isset($arrRepeat['unit'], $arrRepeat['value']) || $arrRepeat['value'] < 1)
					{
						continue;
					}

					$count = 0;
					$intStartTime = $objArticle->startTime;
					$intEndTime = $objArticle->endTime;
					$strtotime = '+ ' . $arrRepeat['value'] . ' ' . $arrRepeat['unit'];

					// Do not include more than 20 recurrences
					while ($count++ < 20)
					{
						if ($objArticle->recurrences > 0 && $count >= $objArticle->recurrences)
						{
							break;
						}

						$intStartTime = strtotime($strtotime, $intStartTime);
						$intEndTime = strtotime($strtotime, $intEndTime);

						if ($intStartTime >= $time)
						{
							$this->addEvent($objArticle, $intStartTime, $intEndTime, $strUrl);
						}
					}
				}
			}
		}

		$count = 0;
		ksort($this->arrEvents);

		$request = System::getContainer()->get('request_stack')->getCurrentRequest();

		if ($request)
		{
			$origScope = $request->attributes->get('_scope');
			$request->attributes->set('_scope', 'frontend');
		}

		// Add the feed items
		foreach ($this->arrEvents as $days)
		{
			foreach ($days as $events)
			{
				foreach ($events as $event)
				{
					if ($arrFeed['maxItems'] > 0 && $count++ >= $arrFeed['maxItems'])
					{
						break 3;
					}

					$objItem = new FeedItem();

					$objItem->title = $event['title'];
					$objItem->link = $event['link'];
					$objItem->published = $event['tstamp'];
					$objItem->begin = $event['startTime'];
					$objItem->end = $event['endTime'];

					if (($objAuthor = UserModel::findById($event['author'])) !== null)
					{
						$objItem->author = $objAuthor->name;
					}

					// Prepare the description
					if ($arrFeed['source'] == 'source_text')
					{
						$strDescription = '';
						$objElement = ContentModel::findPublishedByPidAndTable($event['id'], 'tl_calendar_events');

						if ($objElement !== null)
						{
							// Overwrite the request (see #7756)
							$strRequest = Environment::get('request');
							Environment::set('request', $objItem->link);

							while ($objElement->next())
							{
								$strDescription .= $this->getContentElement($objElement->current());
							}

							Environment::set('request', $strRequest);
						}
					}
					else
					{
						$strDescription = $event['teaser'];
					}

					$strDescription = $this->replaceInsertTags($strDescription, false);
					$objItem->description = $this->convertRelativeUrls($strDescription, $strLink);

					if (\is_array($event['media:content']))
					{
						foreach ($event['media:content'] as $enclosure)
						{
							$objItem->addEnclosure($enclosure, $strLink, 'media:content');
						}
					}

					if (\is_array($event['enclosure']))
					{
						foreach ($event['enclosure'] as $enclosure)
						{
							$objItem->addEnclosure($enclosure, $strLink);
						}
					}

					$objFeed->addItem($objItem);
				}
			}
		}

		if ($request)
		{
			$request->attributes->set('_scope', $origScope);
		}

		$webDir = StringUtil::stripRootDir(System::getContainer()->getParameter('contao.web_dir'));

		// Create the file
		File::putContent($webDir . '/share/' . $strFile . '.xml', $this->replaceInsertTags($objFeed->$strType(), false));
	}

	/**
	 * Add events to the indexer
	 *
	 * @param array   $arrPages
	 * @param integer $intRoot
	 * @param boolean $blnIsSitemap
	 *
	 * @return array
	 */
	public function getSearchablePages($arrPages, $intRoot=0, $blnIsSitemap=false)
	{
		$arrRoot = array();

		if ($intRoot > 0)
		{
			$arrRoot = $this->Database->getChildRecords($intRoot, 'tl_page');
		}

		$arrProcessed = array();
		$time = time();

		// Get all calendars
		$objCalendar = CalendarModel::findByProtected('');

		// Walk through each calendar
		if ($objCalendar !== null)
		{
			while ($objCalendar->next())
			{
				// Skip calendars without target page
				if (!$objCalendar->jumpTo)
				{
					continue;
				}

				// Skip calendars outside the root nodes
				if (!empty($arrRoot) && !\in_array($objCalendar->jumpTo, $arrRoot))
				{
					continue;
				}

				// Get the URL of the jumpTo page
				if (!isset($arrProcessed[$objCalendar->jumpTo]))
				{
					$objParent = PageModel::findWithDetails($objCalendar->jumpTo);

					// The target page does not exist
					if ($objParent === null)
					{
						continue;
					}

					// The target page has not been published (see #5520)
					if (!$objParent->published || ($objParent->start && $objParent->start > $time) || ($objParent->stop && $objParent->stop <= $time))
					{
						continue;
					}

					if ($blnIsSitemap)
					{
						// The target page is protected (see #8416)
						if ($objParent->protected)
						{
							continue;
						}

						// The target page is exempt from the sitemap (see #6418)
						if ($objParent->robots == 'noindex,nofollow')
						{
							continue;
						}
					}

					// Generate the URL
					$arrProcessed[$objCalendar->jumpTo] = $objParent->getAbsoluteUrl(Config::get('useAutoItem') ? '/%s' : '/events/%s');
				}

				$strUrl = $arrProcessed[$objCalendar->jumpTo];

				// Get the items
				$objEvents = CalendarEventsModel::findPublishedDefaultByPid($objCalendar->id);

				if ($objEvents !== null)
				{
					while ($objEvents->next())
					{
						$arrPages[] = sprintf(preg_replace('/%(?!s)/', '%%', $strUrl), ($objEvents->alias ?: $objEvents->id));
					}
				}
			}
		}

		return $arrPages;
	}

	/**
	 * Add an event to the array of active events
	 *
	 * @param CalendarEventsModel $objEvent
	 * @param integer             $intStart
	 * @param integer             $intEnd
	 * @param string              $strUrl
	 * @param string              $strBase
	 */
	protected function addEvent($objEvent, $intStart, $intEnd, $strUrl, $strBase='')
	{
		if ($intEnd < time())
		{
			return; // see #3917
		}

		$intKey = date('Ymd', $intStart);
		$span = self::calculateSpan($intStart, $intEnd);
		$format = $objEvent->addTime ? 'datimFormat' : 'dateFormat';

		/** @var PageModel $objPage */
		global $objPage;

		if ($objPage instanceof PageModel)
		{
			$date = $objPage->$format;
			$dateFormat = $objPage->dateFormat;
			$timeFormat = $objPage->timeFormat;
		}
		else
		{
			// Called in the back end (see #4026)
			$date = Config::get($format);
			$dateFormat = Config::get('dateFormat');
			$timeFormat = Config::get('timeFormat');
		}

		// Add date
		if ($span > 0)
		{
			$title = Date::parse($date, $intStart) . $GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'] . Date::parse($date, $intEnd);
		}
		else
		{
			$title = Date::parse($dateFormat, $intStart) . ($objEvent->addTime ? ' (' . Date::parse($timeFormat, $intStart) . (($intStart < $intEnd) ? $GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'] . Date::parse($timeFormat, $intEnd) : '') . ')' : '');
		}

		// Add title and link
		$title .= ' ' . $objEvent->title;

		// Backwards compatibility (see #8329)
		if ($strBase && !preg_match('#^https?://#', $strUrl))
		{
			$strUrl = $strBase . $strUrl;
		}

		$link = '';

		switch ($objEvent->source)
		{
			case 'external':
				$link = $objEvent->url;
				break;

			case 'internal':
				if (($objTarget = $objEvent->getRelated('jumpTo')) instanceof PageModel)
				{
					/** @var PageModel $objTarget */
					$link = $objTarget->getAbsoluteUrl();
				}
				break;

			case 'article':
				if (($objArticle = ArticleModel::findByPk($objEvent->articleId)) instanceof ArticleModel && ($objPid = $objArticle->getRelated('pid')) instanceof PageModel)
				{
					/** @var PageModel $objPid */
					$link = ampersand($objPid->getAbsoluteUrl('/articles/' . ($objArticle->alias ?: $objArticle->id)));
				}
				break;

			default:
				$link = sprintf(preg_replace('/%(?!s)/', '%%', $strUrl), ($objEvent->alias ?: $objEvent->id));
				break;
		}

		// Store the whole row (see #5085)
		$arrEvent = $objEvent->row();

		// Override link and title
		$arrEvent['link'] = $link;
		$arrEvent['title'] = $title;

		// Clean the RTE output
		$arrEvent['teaser'] = StringUtil::toHtml5($objEvent->teaser);

		// Reset the enclosures (see #5685)
		$arrEvent['enclosure'] = array();
		$arrEvent['media:content'] = array();

		// Add the article image as enclosure
		if ($objEvent->addImage)
		{
			$objFile = FilesModel::findByUuid($objEvent->singleSRC);

			if ($objFile !== null)
			{
				$arrEvent['media:content'][] = $objFile->path;
			}
		}

		// Enclosures
		if ($objEvent->addEnclosure)
		{
			$arrEnclosure = StringUtil::deserialize($objEvent->enclosure, true);

			if (\is_array($arrEnclosure))
			{
				$objFile = FilesModel::findMultipleByUuids($arrEnclosure);

				if ($objFile !== null)
				{
					while ($objFile->next())
					{
						$arrEvent['enclosure'][] = $objFile->path;
					}
				}
			}
		}

		$this->arrEvents[$intKey][$intStart][] = $arrEvent;
	}

	/**
	 * Calculate the span between two timestamps in days
	 *
	 * @param integer $intStart
	 * @param integer $intEnd
	 *
	 * @return integer
	 */
	public static function calculateSpan($intStart, $intEnd)
	{
		return self::unixToJd($intEnd) - self::unixToJd($intStart);
	}

	/**
	 * Convert a UNIX timestamp to a Julian day
	 *
	 * @param integer $tstamp
	 *
	 * @return integer
	 */
	public static function unixToJd($tstamp)
	{
		list($year, $month, $day) = explode(',', date('Y,m,d', $tstamp));

		// Make year a positive number
		$year += ($year < 0 ? 4801 : 4800);

		// Adjust the start of the year
		if ($month > 2)
		{
			$month -= 3;
		}
		else
		{
			$month += 9;
			--$year;
		}

		$sdn  = floor((floor($year / 100) * 146097) / 4);
		$sdn += floor((($year % 100) * 1461) / 4);
		$sdn += floor(($month * 153 + 2) / 5);
		$sdn += $day - 32045;

		return $sdn;
	}

	/**
	 * Return the names of the existing feeds so they are not removed
	 *
	 * @return array
	 */
	public function purgeOldFeeds()
	{
		$arrFeeds = array();
		$objFeeds = CalendarFeedModel::findAll();

		if ($objFeeds !== null)
		{
			while ($objFeeds->next())
			{
				$arrFeeds[] = $objFeeds->alias ?: 'calendar' . $objFeeds->id;
			}
		}

		return $arrFeeds;
	}
}

class_alias(Calendar::class, 'Calendar');
