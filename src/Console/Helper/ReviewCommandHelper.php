<?php

/**
 * @file
 * Contains QualityAssurance\Component\Console\Helper\ReviewCommandHelper.
 */

namespace QualityAssurance\Component\Console\Helper;

use QualityAssurance\Component\Console\Helper\PhingPropertiesHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Finder\Finder;

/**
 * Class ReviewCommandHelper
 * @package QualityAssurance\Component\Console\Helper
 */
class ReviewCommandHelper
{
    /**
     * ReviewCommandHelper constructor.
     *
     * Setup our input output interfaces and other variables.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $application
     */
    public function __construct(InputInterface $input, OutputInterface $output, $application)
    {
        // Set construct properties.
        $this->input = $input;
        $this->output = $output;
        $this->application = $application;
        $this->commands = $this->getSelectedCommands($input, $output, $application);
    }

    /**
     * Public helper function to set the properties before starting a review.
     *
     * @param array $overrides
     *   An array of possible overrides.
     */
    public function setProperties($overrides = array())
    {
        // Start off by fetching the review type.
        $type = $this->input->getOption('type');
        // Get the needed properties to target the QA review on.
        $phingProperties = $this->getPhingProperties($type, $this->input, $this->output);
        if (!empty($overrides)) {
            $this->properties = array_merge($phingProperties, $overrides);
        } else {
            $this->properties = $phingProperties;
        }
    }
    
    /**
     * Start a review process.
     */
    public function startReview($section = false)
    {
        $failbuild = false;

        // Perform the starterkit check if needed.
        if (!empty($this->properties['check-ssk'])) {
            if ($this->executeCommandlines(
                $this->application,
                array('check:ssk' => new ArrayInput(array())),
                $this->output
            )
            ) {
                return 1;
            }
        }

        // Ask for a selection of options if needed.
        $selected = $this->getSelectedOptions($section);
        // Setup a buffered output to capture results of command.
        $buffered_output = new BufferedOutput($this->output->getVerbosity(), true);

        // Loop over each selection to run commands.
        foreach ($selected as $absolute_path => $filename) {
            // Build the commandlines.
            $commandlines = $this->buildCommandlines($absolute_path);
            // Execute commandlines.
            if ($this->executeCommandlines($this->application, $commandlines, $buffered_output)) {
                $failbuild = true;
            }
            // Write the results.
            $this->outputCommandlines($buffered_output, dirname($absolute_path));
        }

        // Perform the theme conflict check.
        if ($this->executeCommandlines($this->application, array('theme:conflict' => new ArrayInput(array())), $this->output)) {
            return 1;
        }

        if ($failbuild) {
            return 1;
        }
    }

    /**
     * Helper function to ask users to select options if needed.
     *
     * @return array
     *   An associative array of options keyed by absolute path and valued by filename.
     */
    protected function getSelectedOptions($section = false)
    {
        // Setup options if it hasn't happened yet.
        if (!isset($this->options)) {
            $this->setOptions($section);
        }

        // Stop for user input to select modules.
        $helperQuestion = new QuestionHelper();
        $helperQuestionText = "Select features, modules and/or themes to QA (seperate with commas): ";
        $question = new ChoiceQuestion($helperQuestionText, array_values($this->options), 0);
        $question->setMultiselect(true);
        $selection = $helperQuestion->ask($this->input, $this->output, $question);

        // If user selects all, add all but that option to the selected options.
        if ($selection[0] == 'Select all' || $this->input->getOption('no-interaction')) {
            array_shift($this->options);
            $selected = $this->options;
        } else {
            // If a targeted selection is made, just add those to the selected options.
            $selected = array_intersect($this->options, $selection);
        }

        return $selected;
    }

    /**
     * Helper function to build the commandlines.
     *
     * @param $absolute_path
     *   The absolute path of the info file or make file.
     * @return array
     *   An associative array containing ArrayInput instances keyed by command name.
     */
    protected function buildCommandlines($absolute_path)
    {
        $pathinfo = pathinfo($absolute_path);
        $directory = $pathinfo['dirname'];
        $extension = $pathinfo['extension'];
        $filename = $extension == 'make' ? $absolute_path : $pathinfo['filename'];

        $command_options = array(
            'directory' => $directory,
            'filename' => $filename,
            'profile' => $this->properties['profile'],
            'standard' => $this->properties['phpcs.config'],
            'project.basedir' => $this->properties['project.basedir'],
        );
        if ($exclude_directories = $this->getSubmoduleDirectories($filename, $directory)) {
            $command_options['exclude-dirs'] = implode(',', $exclude_directories);
        }

        $commandlines = array();
        $commands = $this->commands;
        //unset($commands['scan:coco']);
        foreach ($commands as $command) {
            $command_name = $command->getName();
            $definition = $command->getDefinition();
            $arguments = $definition->getOptions();
            foreach ($command_options as $name => $shared_option) {
                if (isset($arguments[$name])) {
                    $commandlines[$command_name]['--' . $name] = $command_options[$name];
                }
            }
            // Convert commandline to InputArray.
            if (isset($commandlines[$command_name])) {
                $commandlines[$command_name] = new ArrayInput((array) $commandlines[$command_name]);
            }
        }

        return $commandlines;
    }

    /**
     * Helper function to execute the commmandlines array.
     *
     * @param $application
     *   The application of which we need to execute commands.
     * @param $commandlines
     *   An associative array of commandlines keyed by name and valued by ArrayInput.
     * @param $buffered_output
     *   The buffered output on which we capture the results.
     */
    protected function executeCommandlines($application, $commandlines, $buffered_output)
    {
        $failbuild = false;
        foreach ($commandlines as $name => $commandline) {
            $command = $application->find($name);
            if ($command->run($commandline, $buffered_output) !== 0) {
                $failbuild = true;
            }
        }
        return $failbuild;
    }

    /**
     * Helper function to output the commandlines results.
     *
     * @param $buffered_output
     *  The buffered output where the results were captured.
     * @param $path
     *  The path to the folder that was reviewed.
     */
    protected function outputCommandlines($buffered_output, $path)
    {
        if ($content = $buffered_output->fetch()) {
            $title = str_replace(getcwd(), '.', $path);
            $ruler = "<info>" . str_repeat('=', $this->ruler_length) . "</info>";
            $this->output->writeln("");
            $this->output->writeln($ruler);
            if ($title != '.') {
                $this->output->writeln($title);
            } else {
                $this->output->writeln('../' . basename($path));
            }

            $this->output->writeln($ruler);
            $this->output->writeln($content);
        }
    }

    /**
     * Helper function to allow the user to make a selection of commands.
     *
     * @param $input
     *   The command input.
     * @param $output
     *   The command output.
     * @param $application
     *   The application of which to select the commands.
     * @return array
     *   An array of commands.
     */
    protected function getSelectedCommands($input, $output, $application)
    {
        // Get all application commands.
        $commands = $application->all();
        // Unset unwanted commands.
        $unwanted = array('help', 'list', 'check:ssk');
        foreach ($commands as $name => $command) {
            if (in_array($name, $unwanted) ||
                strpos($name, 'review:') === 0 ||
                strpos($name, 'theme:') === 0
            ) {
                unset($commands[$name]);
            }
        }
        // Stop for user input to select commands.
        if ($this->input->getOption('select')) {
            $helperQuestion = new QuestionHelper();
            $helperQuestionText = "Select commands to execute in review (seperate with commas): ";
            $question = new ChoiceQuestion($helperQuestionText, array_keys($commands), 0);
            $question->setMultiselect(true);
            $selection = $helperQuestion->ask($input, $output, $question);

            // Set selected commands.
            if ($selection) {
                return array_intersect_key($commands, array_flip($selection));
            }
        } else {
            return $commands;
        }
    }

    /**
     * Helper function to set initial options.
     *
     * Also sets the ruler length.
     */
    protected function setOptions($section = false)
    {
        $properties = $this->properties;

        switch ($section) {
            case 'theme':
                // Fetch all modules, features and themes into an array.
                $options = $this->getThemeFiles($properties['theme.dir']);
                break;

            default:
                // Fetch all modules, features and themes into an array.
                $info_files = $this->getInfoFiles($properties['lib.dir']);
                // Fetch all make files into an array.
                $make_files = $this->getMakeFiles($properties['resources.dir']);
                // Merge makes and infos into options.
                $options = array_merge($make_files, $info_files);
                // Add the "Select all" option.
                break;
        }

        array_unshift($options, 'Select all');
        // Set the options.
        $this->options = $options;
        // Set the ruler length.
        $this->setRulerLength($options);
    }

    /**
     * Helper function to gather submodule directories to exclude.
     *
     * @param string $filename
     *   The main module filename we don't want to match.
     * @param string $directory
     *   The directory of the module to search through.
     * @return array
     *   An array of submodule directory names.
     */
    protected function getSubmoduleDirectories($filename, $directory)
    {
        $submodule_directories = array();
        $submodules = new Finder();
        $submodules->files()
            ->name('*.info')
            ->notName($filename . '.info')
            ->in($directory)
            ->sortByName();
        foreach ($submodules as $submodule) {
            $submodule_directories[] = basename($submodule->getRelativePath());
        }
        return $submodule_directories;
    }

    /**
     * Helper function to return the needed build properties to target the QA on.
     *
     * @param string $type
     *   The type of QA review: subsite or platform.
     * @param InputInterface $this->input
     *   The input of this command.
     * @param OutputInterface $this->output
     *   The output of this command.
     * @return array
     *   The required properties.
     * @throws \Symfony\Component\Debug\Exception\FatalErrorException
     */
    protected function getPhingProperties($type)
    {
        // Get required properties from the build properties depending on QA review type.
        $phingPropertiesHelper = new PhingPropertiesHelper($this->output);
        $properties = array();
        if ($this->input->getOption('type') == 'subsite') {
            $properties = $phingPropertiesHelper->requestSettings(array(
                'lib.dir' => 'lib.dir',
                'theme.dir' => 'theme.dir',
                'resources.dir' => 'resources.dir',
                'phpcs.config' => 'phpcs.config',
                'profile' => 'profile',
                'project.basedir' => 'project.basedir',
            ));
        } else {
            $properties = $phingPropertiesHelper->requestSettings(array(
                'lib.dir' => 'lib.dir',
                'theme.dir' => 'theme.dir',
                'resources.dir' => 'resources.dir',
                'phpcs.config' => 'phpcs.config',
                'profile' => 'profile',
                'project.basedir' => 'project.basedir',
            ));
        }
        return $properties;
    }

    /**
     * Helper function to get info file select options.
     *
     * @param $path
     *   The path in which to look for the info files.
     * @return array
     *   An associative array of filenames keyed with absolute filepath.
     */
    protected function getInfoFiles($path)
    {
        $options = array();
        // Find all info files in provided path.
        $finder = new Finder();
        $finder->files()
            ->name('*.info')
            ->in($path)
            ->exclude(array('contrib', 'contributed'))
            ->sortByName();
        // Loop over files and build an options array.
        foreach ($finder as $file) {
            $filepathname = $file->getRealPath();
            $filename = basename($filepathname);
            $options[$filepathname] = $filename;
        }
        return $options;
    }
    /**
    * Helper function to get theme  file select options.
    *
    * @param $path
    *   The path in which to look for the make files.
    * @return array
    *   An associative array of filenames keyed with absolute filepath.
    */
    public function getThemeFiles($path)
    {
        $options = array();
        // Find all info files in provided path.
        $finder = new Finder();
        $finder->files()
              ->name('*.info')
              ->in($path . "/themes")
              ->exclude(array('contrib', 'contributed'))
              ->sortByName();
        // Loop over files and build an options array.
        foreach ($finder as $file) {
              $filepathname = $file->getRealPath();
              $filename = basename($filepathname);
              $options[$filepathname] = $filename;
        }
        return $options;
    }

    /**
     * Helper function to get make file select options.
     *
     * @param $path
     *   The path in which to look for the make files.
     * @return array
     *   An associative array of filenames keyed with absolute filepath.
     */
    protected function getMakeFiles($path)
    {
        $options = array();
        // Find all info files in provided path.
        $finder = new Finder();
        $finder->files()
            ->name('*.make')
            ->in($path)
            ->sortByName();
        // Loop over files and build an options array.
        foreach ($finder as $file) {
            $filepathname = $file->getRealPath();
            $filename = basename($filepathname);
            $options[$filepathname] = $filename;
        }
        return $options;
    }

    /**
     * Helper function to the ruler length for the messages.
     *
     * @param array $options
     *   Options array to check for longest value.
     * @return int
     *   An integer that represents the ruler length.
     */
    protected function getRulerLength($options)
    {
        $ruler_length = 80;
        foreach ($options as $path => $filename) {
            $dirname = dirname($path);
            $relative_dirname = str_replace(getcwd(), '.', $dirname);
            $ruler_length = strlen($relative_dirname) > $ruler_length ? strlen($relative_dirname) : $ruler_length;
        }
        return $ruler_length;
    }

    /**
     * Helper function to set the ruler length.
     *
     * @param $options
     */
    protected function setRulerLength($options)
    {
        // Calculate the ruler length for header output.
        $this->ruler_length = $this->getRulerLength($options);
    }
}
