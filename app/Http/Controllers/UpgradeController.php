<?php
/**
 * LaraClassified - Classified Ads Web Application
 * Copyright (c) BedigitCom. All Rights Reserved
 *
 * Website: http://www.bedigit.com
 *
 * LICENSE
 * -------
 * This software is furnished under a license and may be used and copied
 * only in accordance with the terms of such license and with the inclusion
 * of the above copyright notice. If you Purchased from Codecanyon,
 * Please read the full License from here - http://codecanyon.net/licenses/standard
 */

namespace App\Http\Controllers;

/*
 * Increase PHP page execution time for this controller.
 * NOTE: This function has no effect when PHP is running in safe mode (http://php.net/manual/en/ini.sect.safe-mode.php#ini.safe-mode).
 * There is no workaround other than turning off safe mode or changing the time limit (max_execution_time) in the php.ini.
 */
set_time_limit(0);

use App\Helpers\DBTool;
use App\Helpers\Lang\LangManager;
use App\Models\Language;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Jackiedo\DotenvEditor\Facades\DotenvEditor;

class UpgradeController extends Controller
{
	/**
	 * URL: /upgrade
	 *
	 * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
	 */
	public function version()
	{
		// Lunch the installation if the /.env file doesn't exists
		if (!File::exists(base_path('.env'))) {
			return redirect('/install');
		}
		
		// Get eventual new version value & the current (installed) version value
		$lastVersion = getLatestVersion();
		$currentVersion = getCurrentVersion();
		
		// All is Up to Date
		if (version_compare($lastVersion, $currentVersion, '<=')) {
			abort(401);
		}
		
		// Installed version number is NOT found
		if (version_compare('1.0.0', $currentVersion, '>')) {
			$message = "<strong style='color:red;'>ERROR:</strong> Cannot find your current version from the '/.env' file.<br><br>";
			$message .= "<br><strong style='color:green;'>SOLUTION:</strong>";
			$message .= "<br>1. You have to add in the '/.env' file a line like: <strong>APP_VERSION=X.X</strong> (Don't forget to replace <strong>X.X</strong> by your current version)";
			$message .= "<br>2. (Optional) If you are forgot your current version, you have to see it from your backup 'config/app.php' file (it's the last element of the array).";
			$message .= "<br>3. And <strong>refresh this page</strong> to finish upgrading";
			echo '<pre>' . $message . '</pre>';
			exit();
		}
		
		// Go to maintenance with DOWN status
		$exitCode = Artisan::call('down');
		
		// Clear all the cache
		$this->clearCache();
		
		// Upgrade the Database
		$res = $this->upgradeDatabase($lastVersion, $currentVersion);
		if ($res === false) {
			dd('ERROR');
		}
		
		// Update the current version to last version
		$this->setCurrentVersion($lastVersion);
		
		// (Try to) Fill the missing lines in all languages files
		$this->syncLanguageFilesLines();
		
		// Check the Purchase Code
		$this->checkPurchaseCode();
		
		// Clear all the cache
		$this->clearCache();
		
		// Restore system UP status
		$exitCode = Artisan::call('up');
		
		// Success message
		flash("Congratulations! Your website has been upgraded to v" . $lastVersion)->success();
		
		// Redirection
		return redirect('/');
	}
	
	/**
	 * Upgrade the Database & Apply actions
	 *
	 * @param $lastVersion
	 * @param $currentVersion
	 * @return bool
	 */
	private function upgradeDatabase($lastVersion, $currentVersion)
	{
		$migrationFilesPath = base_path('database/upgrade/');
		$migrationFilesPath = rtrim($migrationFilesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$versionsDirsPaths = array_filter(glob($migrationFilesPath . '*'), 'is_dir');
		
		$errorDetect = false;
		$errors = '';
		
		try {
			// Upgrade the website database version by version
			foreach ($versionsDirsPaths as $versionPath) {
				// Get the iterated version
				$version = last(explode(DIRECTORY_SEPARATOR, $versionPath));
				
				// Check the semver format
				if (!preg_match('#^([0-9]+)\.([0-9]+)\.([0-9]+)$#', $version)) {
					continue;
				}
				
				// Load and Apply migration & SQL (queries) files of the "iterated versions",
				// that are greater than the "website current version" && are lower than or equal to the "app's latest version"
				if (version_compare($version, $currentVersion, '>') && version_compare($version, $lastVersion, '<=')) {
					
					// Load and apply update migration
					$updateFile = $migrationFilesPath . $version . '/update.php';
					if (File::exists($updateFile)) {
						require_once($updateFile);
					}
					
					// Load and execute SQL queries
					$updateSqlFile = $migrationFilesPath . $version . '/update.sql';
					if (File::exists($updateSqlFile)) {
						// Import the SQL file
						$res = DBTool::importSqlFile(DB::connection()->getPdo(), $updateSqlFile, DB::getTablePrefix());
						if ($res === false) {
							$errorDetect = true;
							$errors .= '<br>Error occurred in the file: ' . $updateSqlFile;
						}
					}
					
				}
			}
			
			// Check if error is detected
			if ($errorDetect) {
				echo '<pre>' . $errors . '</pre>';
				return false;
			}
		} catch (\Exception $e) {
			$errors .= '<br>Exception => ' . $e->getMessage();
			$errors .= '<br>[ FAILED ]';
			$errors .= '<br>Error occurred during the database upgrade.';
			echo '<pre>' . $errors . '</pre>';
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Update the current version to last version
	 *
	 * @param $last
	 */
	private function setCurrentVersion($last)
	{
		if (!DotenvEditor::keyExists('APP_VERSION')) {
			DotenvEditor::addEmpty();
		}
		DotenvEditor::setKey('APP_VERSION', $last);
		DotenvEditor::save();
	}
	
	/**
	 * (Try to) Fill the missing lines in all languages files
	 */
	private function syncLanguageFilesLines()
	{
		// Get the current Default Language
		$defaultLang = Language::where('default', 1)->first();
		if (empty($defaultLang)) {
			return;
		}
		
		// Init. the language manager
		$manager = new LangManager();
		
		// UPDATE THE 'routes.php' FILE
		$domainMappingPlugin = storage_path('framework/plugins/domainmapping');
		if (!file_exists($domainMappingPlugin)) {
			// Get current values of "resources/lang/[langCode]/routes.php" (Original version)
			$routes = $manager->getFileContent(config_path('larapen/routes.php'));
			
			// Get all the others languages (from filesystem)
			$locales = $manager->getLocales();
			if (!empty($locales)) {
				foreach ($locales as $locale) {
					$filePath = resource_path('lang/' . $locale . '/routes.php');
					$manager->writeFile($filePath, $routes);
				}
			}
		}
		
		// SYNC. THE LANGUAGES FILES LINES
		// Get all the others languages (from DB)
		$languages = Language::where('abbr', '!=', $defaultLang->abbr)->get();
		if ($languages->count() > 0) {
			foreach ($languages as $language) {
				$manager->syncLines($defaultLang->abbr, $language->abbr);
			}
		}
	}
	
	/**
	 * Check the Purchase Code
	 *
	 * @return bool
	 */
	private function checkPurchaseCode()
	{
		// Make sure that the website is properly installed
		if (!File::exists(base_path('.env'))) {
			return false;
		}
		
		// Make the purchase code verification only if 'installed' file exists
		if (!File::exists(storage_path('installed'))) {
			// Get purchase code from DB
			$purchaseCode = config('settings.app.purchase_code');
			
			// Write 'installed' file
			File::put(storage_path('installed'), '');
			
			// Send the purchase code checking
			$apiUrl = config('larapen.core.purchaseCodeCheckerUrl') . $purchaseCode . '&item_id=' . config('larapen.core.itemId');
			$data = \App\Helpers\Curl::fetch($apiUrl);
			
			// Check & Get cURL error by checking if 'data' is a valid json
			if (!isValidJson($data)) {
				$data = json_encode(['valid' => false, 'message' => 'Invalid purchase code. ' . strip_tags($data)]);
			}
			
			// Format object data
			$data = json_decode($data);
			
			// Check if 'data' has the valid json attributes
			if (!isset($data->valid) || !isset($data->message)) {
				$data = json_encode(['valid' => false, 'message' => 'Invalid purchase code. Incorrect data format.']);
				$data = json_decode($data);
			}
			
			// Update 'installed' file
			if ($data->valid == true) {
				File::put(storage_path('installed'), $data->license_code);
			}
		}
		
		return true;
	}
	
	/**
	 * Clear all the cache
	 */
	private function clearCache()
	{
		$this->removeRobotsTxtFile();
		$exitCode = Artisan::call('cache:clear');
		sleep(2);
		$exitCode = Artisan::call('view:clear');
		sleep(1);
		File::delete(File::glob(storage_path('logs') . DIRECTORY_SEPARATOR . 'laravel*.log'));
	}
	
	/**
	 * Remove the robots.txt file (It will be re-generated automatically)
	 */
	private function removeRobotsTxtFile()
	{
		$robotsFile = public_path('robots.txt');
		if (File::exists($robotsFile)) {
			File::delete($robotsFile);
		}
	}
}
