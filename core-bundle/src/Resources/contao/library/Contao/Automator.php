<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\OptIn\OptIn;
use FOS\HttpCacheBundle\CacheManager;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Provide methods to run automated jobs.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Automator extends System
{
	/**
	 * Make the constuctor public
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Purge the search tables
	 */
	public function purgeSearchTables()
	{
		$searchIndexer = System::getContainer()->get('contao.search.indexer');

		// The search indexer is disabled
		if (null === $searchIndexer)
		{
			return;
		}

		// Clear the index
		$searchIndexer->clear();

		$strCachePath = StringUtil::stripRootDir(System::getContainer()->getParameter('kernel.cache_dir'));

		// Purge the cache folder
		$objFolder = new Folder($strCachePath . '/contao/search');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the search tables', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the undo table
	 */
	public function purgeUndoTable()
	{
		$objDatabase = Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_undo");

		// Add a log entry
		$this->log('Purged the undo table', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the version table
	 */
	public function purgeVersionTable()
	{
		$objDatabase = Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_version");

		// Add a log entry
		$this->log('Purged the version table', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the system log
	 */
	public function purgeSystemLog()
	{
		$objDatabase = Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_log");

		// Add a log entry
		$this->log('Purged the system log', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the crawl queue
	 */
	public function purgeCrawlQueue()
	{
		$objDatabase = Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_crawl_queue");

		// Add a log entry
		$this->log('Purged the crawl queue', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the image cache
	 */
	public function purgeImageCache()
	{
		$container = System::getContainer();
		$strTargetPath = StringUtil::stripRootDir($container->getParameter('contao.image.target_dir'));
		$strRootDir = $container->getParameter('kernel.project_dir');

		// Walk through the subfolders
		foreach (scan($strRootDir . '/' . $strTargetPath) as $dir)
		{
			if (strncmp($dir, '.', 1) !== 0)
			{
				$objFolder = new Folder($strTargetPath . '/' . $dir);
				$objFolder->purge();
			}
		}

		// Also empty the shared cache so there are no links to deleted images
		$this->purgePageCache();

		// Add a log entry
		$this->log('Purged the image cache', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the script cache
	 */
	public function purgeScriptCache()
	{
		// assets/js and assets/css
		foreach (array('assets/js', 'assets/css') as $dir)
		{
			// Purge the folder
			$objFolder = new Folder($dir);
			$objFolder->purge();
		}

		// Recreate the internal style sheets
		$this->import(StyleSheets::class, 'StyleSheets');
		$this->StyleSheets->updateStyleSheets();

		// Also empty the shared cache so there are no links to deleted scripts
		$this->purgePageCache();

		// Add a log entry
		$this->log('Purged the script cache', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the shared cache
	 */
	public function purgePageCache()
	{
		$container = System::getContainer();

		if (!$container->has('fos_http_cache.cache_manager'))
		{
			$this->log('Cannot purge the shared cache; invalid reverse proxy configuration', __METHOD__, TL_ERROR);

			return;
		}

		/** @var CacheManager $cacheManager */
		$cacheManager = $container->get('fos_http_cache.cache_manager');

		if (!$cacheManager->supports(CacheManager::CLEAR))
		{
			$this->log('Cannot purge the shared cache; invalid reverse proxy configuration', __METHOD__, TL_ERROR);

			return;
		}

		$cacheManager->clearCache();

		// Add a log entry
		$this->log('Purged the shared cache', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the search cache
	 */
	public function purgeSearchCache()
	{
		$strCacheDir = StringUtil::stripRootDir(System::getContainer()->getParameter('kernel.cache_dir'));

		$objFolder = new Folder($strCacheDir . '/contao/search');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the search cache', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the internal cache
	 */
	public function purgeInternalCache()
	{
		$container = System::getContainer();

		$clearer = $container->get('contao.cache.clear_internal');
		$clearer->clear($container->getParameter('kernel.cache_dir'));

		// Add a log entry
		$this->log('Purged the internal cache', __METHOD__, TL_CRON);
	}

	/**
	 * Purge the temp folder
	 */
	public function purgeTempFolder()
	{
		// Purge the folder
		$objFolder = new Folder('system/tmp');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the temp folder', __METHOD__, TL_CRON);
	}

	/**
	 * Purge registrations that have not been activated within 24 hours
	 */
	public function purgeRegistrations()
	{
		$objMember = MemberModel::findExpiredRegistrations();

		if ($objMember === null)
		{
			return;
		}

		while ($objMember->next())
		{
			$objMember->delete();
		}

		// Add a log entry
		$this->log('Purged the unactivated member registrations', __METHOD__, TL_CRON);
	}

	/**
	 * Purge opt-in tokens
	 */
	public function purgeOptInTokens()
	{
		/** @var OptIn $optIn */
		$optIn = System::getContainer()->get('contao.opt-in');
		$optIn->purgeTokens();

		// Add a log entry
		$this->log('Purged the expired double opt-in tokens', __METHOD__, TL_CRON);
	}

	/**
	 * Remove old XML files from the share directory
	 *
	 * @param boolean $blnReturn If true, only return the finds and don't delete
	 *
	 * @return array An array of old XML files
	 */
	public function purgeXmlFiles($blnReturn=false)
	{
		$arrFeeds = array();
		$objDatabase = Database::getInstance();

		// XML sitemaps
		$objFeeds = $objDatabase->execute("SELECT sitemapName FROM tl_page WHERE type='root' AND createSitemap=1 AND sitemapName!=''");

		while ($objFeeds->next())
		{
			$arrFeeds[] = $objFeeds->sitemapName;
		}

		// HOOK: preserve third party feeds
		if (isset($GLOBALS['TL_HOOKS']['removeOldFeeds']) && \is_array($GLOBALS['TL_HOOKS']['removeOldFeeds']))
		{
			foreach ($GLOBALS['TL_HOOKS']['removeOldFeeds'] as $callback)
			{
				$this->import($callback[0]);
				$arrFeeds = array_merge($arrFeeds, $this->{$callback[0]}->{$callback[1]}());
			}
		}

		// Delete the old files
		if (!$blnReturn)
		{
			$shareDir = System::getContainer()->getParameter('contao.web_dir') . '/share';

			foreach (scan($shareDir) as $file)
			{
				if (is_dir($shareDir . '/' . $file))
				{
					continue; // see #6652
				}

				$objFile = new File(StringUtil::stripRootDir($shareDir) . '/' . $file);

				if ($objFile->extension == 'xml' && !\in_array($objFile->filename, $arrFeeds))
				{
					$objFile->delete();
				}
			}
		}

		return $arrFeeds;
	}

	/**
	 * Generate the Google XML sitemaps
	 *
	 * @param integer $intId The root page ID
	 */
	public function generateSitemap($intId=0)
	{
		$time = Date::floorToMinute();
		$objDatabase = Database::getInstance();

		$this->purgeXmlFiles();

		$strQuery = "SELECT id, language, sitemapName FROM tl_page WHERE type='root' AND createSitemap='1' AND sitemapName!='' AND published='1' AND (start='' OR start<='$time') AND (stop='' OR stop>'$time')";

		// Get a particular root page
		if ($intId > 0)
		{
			$strQuery .= ' AND id=' . (int) $intId;
		}

		$objRoot = $objDatabase->execute($strQuery);

		// Return if there are no pages
		if ($objRoot->numRows < 1)
		{
			return;
		}

		// Create the XML file
		while ($objRoot->next())
		{
			$objFile = new File(StringUtil::stripRootDir(System::getContainer()->getParameter('contao.web_dir')) . '/share/' . $objRoot->sitemapName . '.xml');

			$objFile->truncate();
			$objFile->append('<?xml version="1.0" encoding="UTF-8"?>');
			$objFile->append('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">');

			// Find the searchable pages
			$arrPages = Backend::findSearchablePages($objRoot->id, '', true);

			// HOOK: take additional pages
			if (isset($GLOBALS['TL_HOOKS']['getSearchablePages']) && \is_array($GLOBALS['TL_HOOKS']['getSearchablePages']))
			{
				foreach ($GLOBALS['TL_HOOKS']['getSearchablePages'] as $callback)
				{
					$this->import($callback[0]);
					$arrPages = $this->{$callback[0]}->{$callback[1]}($arrPages, $objRoot->id, true, $objRoot->language);
				}
			}

			// Add pages
			foreach ($arrPages as $strUrl)
			{
				$strUrl = explode('/', $strUrl, 4);

				if (isset($strUrl[3]))
				{
					$strUrl[3] = rawurlencode($strUrl[3]);
					$strUrl[3] = str_replace(array('%2F', '%3F', '%3D', '%26', '%5B', '%5D', '%25'), array('/', '?', '=', '&', '[', ']', '%'), $strUrl[3]);
				}

				$strUrl = implode('/', $strUrl);
				$strUrl = ampersand($strUrl);

				$objFile->append('  <url><loc>' . $strUrl . '</loc></url>');
			}

			$objFile->append('</urlset>');
			$objFile->close();

			// Add a log entry
			$this->log('Generated sitemap "' . $objRoot->sitemapName . '.xml"', __METHOD__, TL_CRON);
		}
	}

	/**
	 * Regenerate the XML files
	 */
	public function generateXmlFiles()
	{
		// Sitemaps
		$this->generateSitemap();

		// HOOK: add custom jobs
		if (isset($GLOBALS['TL_HOOKS']['generateXmlFiles']) && \is_array($GLOBALS['TL_HOOKS']['generateXmlFiles']))
		{
			foreach ($GLOBALS['TL_HOOKS']['generateXmlFiles'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}();
			}
		}

		// Also empty the shared cache so there are no links to deleted files
		$this->purgePageCache();

		// Add a log entry
		$this->log('Regenerated the XML files', __METHOD__, TL_CRON);
	}

	/**
	 * Generate the symlinks in the web/ folder
	 */
	public function generateSymlinks()
	{
		$container = System::getContainer();

		$command = $container->get('contao.command.symlinks');
		$status = $command->run(new ArgvInput(array()), new NullOutput());

		// Add a log entry
		if ($status > 0)
		{
			$this->log('The symlinks could not be regenerated', __METHOD__, TL_ERROR);
		}
		else
		{
			$this->log('Regenerated the symlinks', __METHOD__, TL_CRON);
		}
	}

	/**
	 * Generate the internal cache
	 */
	public function generateInternalCache()
	{
		$container = System::getContainer();

		$warmer = $container->get('contao.cache.warm_internal');
		$warmer->warmUp($container->getParameter('kernel.cache_dir'));

		// Add a log entry
		$this->log('Generated the internal cache', __METHOD__, TL_CRON);
	}

	/**
	 * Rotate the log files
	 *
	 * @deprecated Deprecated since Contao 4.0, to be removed in Contao 5.0.
	 *             Use the logger service instead, which rotates its log files automatically.
	 */
	public function rotateLogs()
	{
		@trigger_error('Using Automator::rotateLogs() has been deprecated and will no longer work in Contao 5.0. Use the logger service instead, which rotates its log files automatically.', E_USER_DEPRECATED);

		$projectDir = System::getContainer()->getParameter('kernel.project_dir');
		$arrFiles = preg_grep('/\.log$/', scan($projectDir . '/system/logs'));

		foreach ($arrFiles as $strFile)
		{
			// Ignore Monolog log files (see #2579)
			if (preg_match('/-\d{4}-\d{2}-\d{2}\.log$/', $strFile))
			{
				continue;
			}

			$objFile = new File('system/logs/' . $strFile . '.9');

			// Delete the oldest file
			if ($objFile->exists())
			{
				$objFile->delete();
			}

			// Rotate the files (e.g. error.log.4 becomes error.log.5)
			for ($i=8; $i>0; $i--)
			{
				$strGzName = 'system/logs/' . $strFile . '.' . $i;

				if (file_exists($projectDir . '/' . $strGzName))
				{
					$objFile = new File($strGzName);
					$objFile->renameTo('system/logs/' . $strFile . '.' . ($i+1));
				}
			}

			// Add .1 to the latest file
			$objFile = new File('system/logs/' . $strFile);
			$objFile->renameTo('system/logs/' . $strFile . '.1');
		}
	}
}

class_alias(Automator::class, 'Automator');
