
file(GLOB_RECURSE SrcFiles
     "./*.cpp"
)

set(testLib loader_phpdetection)

set (targetName  "loader_test")

add_executable(${targetName} ${SrcFiles})

target_link_libraries(${targetName}
    PRIVATE ${testLib}
    PRIVATE gtest::gtest)

target_include_directories(${targetName}
    PRIVATE "../code"
    "${GTest_INCLUDE_DIRS}")

add_test(NAME ${targetName}
    COMMAND ${targetName})
